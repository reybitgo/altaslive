<?php
class ShopRefund
{
    /**
 * Refund states that block delivered → completed (§0.5 T24).
 *
 * BOTH open AND approved block. An approved-but-unsettled refund is money
 * that has been promised to the buyer and not yet returned; completing the
 * order underneath it would hide that. This model previously counted only
 * status='open', so a refund sitting in 'approved' let the buyer confirm
 * receipt and let the auto-complete timer fire (defect M3). Both callers of
 * the old check — ShopOrder::canMemberAct() and ShopOrder::autoComplete() —
 * are corrected by changing this one list.
 */
public const BLOCKING_STATUSES = ['open', 'approved'];

/** Refund task lifecycle: open → approved → processed (closed only by a
     * receipt). denied covers both "denied" and a buyer's own cancellation.
     *
     * These five values are the DDL vocabulary (install.sql, shop_refunds.status).
     * The model previously wrote 'paid', which the ENUM does not accept —
     * MySQL rejected it with ERROR 1265 and the whole settlement failed
     * (defect C1, tmp/shop/remedy.md). Do not reintroduce a sixth value without
     * a migration; tests/SchemaDriftTest.php enforces the agreement.
     */
    public const STATUSES = ['open', 'approved', 'processed', 'denied', 'closed'];

    /**
     * shop_refunds.cause values. Kept in step with the ENUM in install.sql;
     * tests/SchemaDriftTest.php asserts the agreement.
     */
    public const CAUSES = ['admin_cancel', 'delivery_failure', 'customer_dispute', 'other'];

    public static function forOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_refunds WHERE order_id = ? ORDER BY created_at ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT r.*, o.member_id FROM shop_refunds r JOIN shop_orders o ON o.id = r.order_id WHERE r.id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function open(int $orderId): int
    {
        $ph  = implode(',', array_fill(0, count(self::BLOCKING_STATUSES), '?'));
        $st  = db()->prepare("SELECT COUNT(*) FROM shop_refunds WHERE order_id = ? AND status IN ({$ph})");
        $st->execute(array_merge([$orderId], self::BLOCKING_STATUSES));
        return (int) $st->fetchColumn();
    }

    /**
     * Segregation of duties: an admin may buy but may never refund their own
     * order. Lives in the model so every entry point gets it — the UI, a future
     * API, or a cron. Defect H6: the guard previously existed only inside
     * AdminController::orderTransition, leaving four sibling endpoints with the
     * view's hidden button as their only protection.
     *
     * Takes a memberId rather than a row so callers do not have to remember to
     * select the right join.
     */
    private static function assertNotSelfReview(int $memberId): void
    {
        // CLI and cron have no session, so there is no human to segregate from.
        if (!class_exists('Auth')) {
            return;
        }
        if (Auth::isAdmin() && $memberId > 0 && $memberId === Auth::id()) {
            throw new RuntimeException('Self-review refused: admins cannot refund their own orders.');
        }
    }

    /** The buyer behind an order, or 0 when the order does not exist. */
    private static function buyerOf(int $orderId): int
    {
        $st = db()->prepare("SELECT member_id FROM shop_orders WHERE id = ?");
        $st->execute([$orderId]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    public static function create(
        int $orderId,
        float $amount,
        string $cause,
        ?string $destination = null,
        ?string $promisedAt = null,
        ?int $requestedBy = null
    ): int {
        self::assertNotSelfReview(self::buyerOf($orderId));
        // Without this guard an unrecognised cause reaches MySQL and dies with
        // ERROR 1265 "Data truncated for column 'cause'", which surfaced as an
        // uncaught PDOException (a 500) because the controller only caught
        // InvalidArgumentException (defect H4).
        if (!in_array($cause, self::CAUSES, true)) {
            throw new InvalidArgumentException('Unknown refund cause: ' . $cause);
        }
        $st = db()->prepare(
            "INSERT INTO shop_refunds (order_id, amount, cause, destination, promised_at, requested_by, status)
             VALUES (?, ?, ?, ?, ?, ?, 'open')"
        );
        $st->execute([$orderId, $amount, $cause, $destination, $promisedAt, $requestedBy]);
        return (int) db()->lastInsertId();
    }

    public static function approve(int $id, int $adminId): bool
    {
        $refund = self::find($id);
        if (!$refund) return false;
        self::assertNotSelfReview((int) $refund['member_id']);
        $st = db()->prepare("UPDATE shop_refunds SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'open'");
        $st->execute([$adminId, $id]);
        return $st->rowCount() === 1;
    }

    /**
     * Close a refund as paid — refuses an empty receipt reference (the
     * "closed only by a receipt" rule, plan §2.3 table 8). E-wallet
     * destinations are credited through the ledger in the same call.
     */
    public static function markPaid(int $id, int $adminId, string $receiptRef): bool
    {
        if (trim($receiptRef) === '') {
            throw new InvalidArgumentException('A receipt reference is required to close a refund.');
        }

        $refund = self::find($id);
        if (!$refund || $refund['status'] !== 'approved') {
            return false;
        }
        self::assertNotSelfReview((int) $refund['member_id']);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // M6 (Phase 6.5): decide from the `method` ENUM, not from free
                // text. This used to be
                //   str_contains(strtolower($destination), 'wallet')
                // which is fragile in both directions: a destination of
                // "Cash payout to GCash wallet later" would credit an e-wallet
                // for a cash payout, and "bank transfer" would not. The column
                // carries no constraint, so any admin typo changed who got paid.
                //
                // shop_refunds.method is ENUM('ewallet','cash','bank','manual')
                // and was defined but never read — that is the contract. Only an
                // explicit e-wallet method credits the wallet; everything else is
                // settled out of band and closed as processed.
                $method = (string) ($refund['method'] ?? '');
                if ($method === 'ewallet') {
                    Ewallet::credit(
                        (int) $refund['member_id'],
                        (float) $refund['amount'],
                        (int) $refund['order_id'],
                        'shop_order',
                        'Shop order refund (receipt ' . $receiptRef . ')'
                    );
                }

            $st = $pdo->prepare(
                "UPDATE shop_refunds SET status = 'processed', processed_by = ?, processed_at = NOW(), receipt_ref = ? WHERE id = ? AND status = 'approved'"
            );
            $st->execute([$adminId, mb_substr($receiptRef, 0, 80), $id]);
            if ($st->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }

            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Deny / cancel an open or approved refund task. */
    public static function deny(int $id, int $adminId, string $note = ''): bool
    {
        $refund = self::find($id);
        if (!$refund) return false;
        self::assertNotSelfReview((int) $refund['member_id']);
        $st = db()->prepare("UPDATE shop_refunds SET status = 'denied', processed_by = ?, processed_at = NOW(), note = ? WHERE id = ? AND status IN ('open','approved')");
        $st->execute([$adminId, mb_substr($note, 0, 500), $id]);
        return $st->rowCount() === 1;
    }

    /** Blocks delivered → completed while open (§0.5 T24). */
    public static function hasOpen(int $orderId): bool
    {
        return self::open($orderId) > 0;
    }
}
