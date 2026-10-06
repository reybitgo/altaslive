<?php
class Product {
    public static function find(int $id): ?array {
        $st = db()->prepare("SELECT * FROM products WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    public static function all(bool $activeOnly = false): array {
        $sql = "SELECT * FROM products";
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY name ASC";
        return db()->query($sql)->fetchAll();
    }
    public static function allPaginated(int $page = 1, int $perPage = 25): array {
        return paginate("SELECT * FROM products ORDER BY name ASC", [], $page, $perPage);
    }
    public static function active(): array {
        return self::all(true);
    }
    public static function reservedStock(int $productId): int {
        $st = db()->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM shop_order_items oi JOIN shop_orders o ON o.id = oi.order_id WHERE oi.product_id = ? AND oi.reservation_state IN ('reserved','committed') AND o.status IN ('pending','payment_review','payment_failed','paid','packing','ready_to_ship','on_hold')");
        $st->execute([$productId]);
        return (int)$st->fetchColumn();
    }
    public static function availableStock(int $productId): int {
        $product = self::find($productId);
        if (!$product) return 0;
        return max(0, (int)$product['stock'] - self::reservedStock($productId));
    }
    public static function save(array $data, ?int $id = null): int {
        $pdo = db();
        $fields = [
            'sku' => trim($data['sku'] ?? ''),
            'name' => trim($data['name'] ?? ''),
            'product_type' => 'physical',
            'price' => (float)($data['price'] ?? 0),
            'product_pv' => 0.00,
            'pv_value' => 0.00,
            'stock' => (int)($data['stock'] ?? 0),
            'image_url' => $data['image_url'] ?? null,
            'short_description' => trim($data['short_description'] ?? ''),
            'description' => trim($data['description'] ?? ''),
            'status' => $data['status'] ?? 'active',
        ];
        if ($fields['sku'] === '') $fields['sku'] = null;
        if ($fields['image_url'] === '') $fields['image_url'] = null;
        if ($id) {
            $sets = []; $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = $k . ' = ?';
                $vals[] = $v;
            }
            $vals[] = $id;
            $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
        } else {
            $cols = array_keys($fields);
            $ph = array_fill(0, count($cols), '?');
            $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')')->execute(array_values($fields));
            $id = (int)$pdo->lastInsertId();
        }
        return $id;
    }
    public static function delete(int $id): bool {
        $st = db()->prepare('SELECT COUNT(*) FROM shop_order_items WHERE product_id = ?');
        $st->execute([$id]);
        $inUse = (int)$st->fetchColumn();
        if ($inUse > 0) return false;
        $product = self::find($id);
        if ($product && !empty($product['image_url'])) {
            delete_uploaded_file($product['image_url']);
        }
        db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        return true;
    }
}
