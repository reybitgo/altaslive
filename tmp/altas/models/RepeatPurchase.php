<?php

/**
 * @deprecated This model is retained for backward compatibility.
 * All new order operations use RepeatPurchaseOrder with the cart-based flow.
 */
class RepeatPurchase
{
    public static function find(int $id): ?array
    {
        $st = db()->prepare('
            SELECT o.id, o.member_id, oi.product_id, oi.quantity,
                   o.total_pv, o.total_price, o.binary_position,
                   o.payment_method, o.proof_image, o.paid_at,
                   o.status, o.approved_by, o.approved_at, o.created_at,
                   p.name AS product_name, p.image_url AS product_image,
                   m.username AS member_username
            FROM   repeat_purchase_orders o
            JOIN   repeat_purchase_order_items oi ON oi.order_id = o.id
            JOIN   products p ON p.id = oi.product_id
            JOIN   users    m ON m.id = o.member_id
            WHERE  o.id = ?
            LIMIT 1
        ');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function forMember(int $memberId, int $page = 1, int $perPage = 20): array
    {
        $pdo = db();

        $countSt = $pdo->prepare("SELECT COUNT(*) FROM repeat_purchase_orders WHERE member_id = ?");
        $countSt->execute([$memberId]);
        $total = (int)$countSt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page       = max(1, min($page, $totalPages));
        $offset     = ($page - 1) * $perPage;

        $st = $pdo->prepare("
            SELECT o.*,
                   first_item.product_id, first_item.quantity,
                   first_item.unit_price, first_item.unit_pv,
                   first_item.product_name, first_item.product_image,
                   first_item.item_count
            FROM   repeat_purchase_orders o
            LEFT JOIN (
                SELECT oi.order_id,
                       MIN(oi.product_id) AS product_id,
                       MIN(oi.quantity)   AS quantity,
                       MIN(oi.unit_price) AS unit_price,
                       MIN(oi.unit_pv)    AS unit_pv,
                       MIN(p.name)        AS product_name,
                       MIN(p.image_url)   AS product_image,
                       COUNT(*)           AS item_count
                FROM   repeat_purchase_order_items oi
                JOIN   products p ON p.id = oi.product_id
                GROUP BY oi.order_id
            ) first_item ON first_item.order_id = o.id
            WHERE  o.member_id = ?
            ORDER BY o.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $st->execute([$memberId, $perPage, $offset]);

        return [
            'data'        => $st->fetchAll(),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'has_prev'    => $page > 1,
            'has_next'    => $page < $totalPages,
        ];
    }

    public static function pending(int $page = 1, int $perPage = 25): array
    {
        return paginate(
            "SELECT o.id, o.member_id, o.total_pv, o.total_price, o.status, o.approved_by, o.approved_at, o.created_at,
                    fi.product_id, fi.quantity, fi.product_name, fi.product_image,
                    m.username AS member_username, m.full_name AS member_full_name
             FROM   repeat_purchase_orders o
             JOIN   users m ON m.id = o.member_id
             LEFT JOIN (
                 SELECT oi.order_id,
                        MIN(oi.product_id) AS product_id,
                        MIN(oi.quantity)   AS quantity,
                        MIN(p.name)        AS product_name,
                        MIN(p.image_url)   AS product_image
                 FROM   repeat_purchase_order_items oi
                 JOIN   products p ON p.id = oi.product_id
                 GROUP BY oi.order_id
             ) fi ON fi.order_id = o.id
             WHERE  o.status = 'pending'
             ORDER BY o.created_at ASC",
            [],
            $page,
            $perPage
        );
    }

    public static function all(int $page = 1, int $perPage = 25): array
    {
        return paginate(
            "SELECT o.id, o.member_id, o.total_pv, o.total_price, o.status, o.approved_by, o.approved_at, o.created_at,
                    fi.product_id, fi.quantity, fi.product_name, fi.product_image,
                    m.username AS member_username, m.full_name AS member_full_name
             FROM   repeat_purchase_orders o
             JOIN   users m ON m.id = o.member_id
             LEFT JOIN (
                 SELECT oi.order_id,
                        MIN(oi.product_id) AS product_id,
                        MIN(oi.quantity)   AS quantity,
                        MIN(p.name)        AS product_name,
                        MIN(p.image_url)   AS product_image
                 FROM   repeat_purchase_order_items oi
                 JOIN   products p ON p.id = oi.product_id
                 GROUP BY oi.order_id
             ) fi ON fi.order_id = o.id
             ORDER BY o.created_at DESC",
            [],
            $page,
            $perPage
        );
    }

    public static function create(int $memberId, int $productId, int $quantity): int
    {
        $pdo = db();
        $product = Product::find($productId);
        if (!$product || $product['status'] !== 'active') {
            throw new InvalidArgumentException('Product not found or inactive.');
        }
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $unitPv   = (float)$product['product_pv'] * ((float)$product['pv_value'] / 100);
        $unitPrice = (float)$product['price'];
        $totalPv   = $unitPv * $quantity;
        $totalPrice = $unitPrice * $quantity;

        $pdo->beginTransaction();
        try {
            $pdo->prepare("
                INSERT INTO repeat_purchase_orders
                  (member_id, total_pv, total_price, binary_position, payment_method, status, created_at)
                VALUES (?, ?, ?, 'left', 'ewallet', 'pending', NOW())
            ")->execute([$memberId, $totalPv, $totalPrice]);

            $orderId = (int)$pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO repeat_purchase_order_items
                  (order_id, product_id, quantity, unit_price, unit_pv, total_price, total_pv)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orderId, $productId, $quantity, $unitPrice, $unitPv, $totalPrice, $totalPv]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $orderId;
    }

    public static function approve(int $purchaseId, int $adminId): bool
    {
        $pdo = db();
        $purchase = self::find($purchaseId);
        if (!$purchase || $purchase['status'] !== 'pending') {
            return false;
        }

        $st = $pdo->prepare("
            UPDATE repeat_purchase_orders
            SET status = 'approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $st->execute([$adminId, $purchaseId]);

        $verify = $pdo->prepare("SELECT id FROM repeat_purchase_orders WHERE id = ? AND status = 'approved'");
        $verify->execute([$purchaseId]);
        if (!$verify->fetch()) {
            return false;
        }

        Commission::processProductPV($purchaseId);
        return true;
    }

    public static function reject(int $purchaseId, int $adminId): bool
    {
        $st = db()->prepare("
            UPDATE repeat_purchase_orders
            SET status = 'rejected',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ");
        $st->execute([$adminId, $purchaseId]);
        return (int)$st->rowCount() > 0;
    }
}
