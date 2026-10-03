<?php

/**
 * @file   models/Product.php
 * @brief  Product catalog for repeat-purchase PV (Phase 5)
 */
class Product
{
    public static function find(int $id): ?array
    {
        $st = db()->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM products';
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= ' ORDER BY name ASC';
        return db()->query($sql)->fetchAll();
    }

    public static function allPaginated(int $page = 1, int $perPage = 25): array
    {
        return paginate('SELECT * FROM products ORDER BY name ASC', [], $page, $perPage);
    }

    public static function active(): array
    {
        return self::all(true);
    }

    public static function reservedStock(int $productId): int
    {
        $st = db()->prepare(
            "SELECT COALESCE(SUM(oi.quantity), 0)
               FROM repeat_purchase_order_items oi
               JOIN repeat_purchase_orders o ON o.id = oi.order_id
              WHERE oi.product_id = ? AND o.status IN ('pending','paid','approved')"
        );
        $st->execute([$productId]);
        return (int)$st->fetchColumn();
    }

    public static function availableStock(int $productId): int
    {
        $product = self::find($productId);
        if (!$product) {
            return 0;
        }
        return max(0, (int)$product['stock'] - self::reservedStock($productId));
    }

    public static function getUnilevelLevels(int $productId): array
    {
        $st = db()->prepare(
            'SELECT level, pv_pct FROM product_unilevel_levels WHERE product_id = ? ORDER BY level'
        );
        $st->execute([$productId]);
        $rows   = $st->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[(int)$r['level']] = (float)$r['pv_pct'];
        }
        return $result;
    }

    public static function withUnilevelLevels(int $id): ?array
    {
        $product = self::find($id);
        if (!$product) return null;
        $product['unilevel_levels'] = self::getUnilevelLevels($id);
        return $product;
    }

    public static function unilevelProductBonus(float $effPv, float $pvPct): float
    {
        if ($pvPct <= 0) return 0.00;
        $rate = (float)setting('pv_per_peso_rate', '1.0000');
        return $effPv * ($pvPct / 100) * $rate;
    }

    /**
     * Save or update a product.
     *
     * @param array $data name, price, product_pv, pv_value, stock, status, image_url, short_description, description
     */
    public static function save(array $data, ?int $id = null): int
    {
        $pdo = db();

        $fields = [
            'name'             => trim($data['name'] ?? ''),
            'price'            => (float)($data['price'] ?? 0),
            'product_pv'       => (float)($data['product_pv'] ?? 0),
            'pv_value'         => (float)($data['pv_value'] ?? 100.00),
            'stock'            => (int)($data['stock'] ?? 0),
            'image_url'        => $data['image_url'] ?? null,
            'short_description'=> trim($data['short_description'] ?? ''),
            'description'      => trim($data['description'] ?? ''),
            'status'           => $data['status'] ?? 'active',
        ];

        if ($fields['image_url'] === '') {
            $fields['image_url'] = null;
        }

        if ($id) {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($vals);
        } else {
            $cols = array_keys($fields);
            $placeholders = array_fill(0, count($cols), '?');
            $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')')
                ->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
        }

        // Save unilevel levels
        $pdo->prepare("DELETE FROM product_unilevel_levels WHERE product_id = ?")
            ->execute([$id]);
        $st = $pdo->prepare("INSERT INTO product_unilevel_levels (product_id, level, pv_pct) VALUES (?, ?, ?)");
        for ($lvl = 1; $lvl <= 10; $lvl++) {
            $pvPct = (float)($data['unilevel_levels'][$lvl] ?? 0);
            $st->execute([$id, $lvl, $pvPct]);
        }

        return $id;
    }

    public static function delete(int $id): bool
    {
        $inUse = (int)db()->query("SELECT COUNT(*) FROM repeat_purchase_order_items WHERE product_id = {$id}")->fetchColumn();
        if ($inUse > 0) {
            return false;
        }

        $product = self::find($id);
        if ($product) {
            delete_uploaded_file($product['image_url'] ?? null);
        }

        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        return true;
    }
}
