<?php
class ShopRefund
{
    /** Refund task lifecycle: open → approved → paid (closed only by receipt). */
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
        $st = db()->prepare("SELECT COUNT(*) FROM shop_refunds WHERE order_id = ? AND status = 'open'");
        $st->execute([$orderId]);
        return (int) $st->fetchColumn();
    }

    public static function create(
        int $orderId,
        float $amount,
        string $cause,
        ?string $destination = null,
        ?string $promisedAt = null,
        ?int $requestedBy = null
    ): int {
        $st = db()->prepare(
            "INSERT INTO shop_refunds (order_id, amount, cause, destination, promised_at, requested_by, status)
             VALUES (?, ?, ?, ?, ?, ?, 'open')"
        );
        $st->execute([$orderId, $amount, $cause, $destination, $promisedAt, $requestedBy]);
        return (int) db()->lastInsertId();
    }

    public static function approve(int $id, int $adminId): bool
    {
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

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $destination = strtolower((string) ($refund['destination'] ?? ''));
            if ($destination === 'ewallet' || str_contains($destination, 'wallet')) {
                Ewallet::credit(
                    (int) $refund['member_id'],
                    (float) $refund['amount'],
                    (int) $refund['order_id'],
                    'shop_order',
                    'Shop order refund (receipt ' . $receiptRef . ')'
                );
            }

            $st = $pdo->prepare(
                "UPDATE shop_refunds SET status = 'paid', processed_by = ?, processed_at = NOW(), receipt_ref = ? WHERE id = ? AND status = 'approved'"
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
