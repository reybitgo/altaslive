<?php
class ShopShipment
{
    public static function activeFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE order_id = ? AND state = 'active' ORDER BY seq DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }
    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    public static function byOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_shipments WHERE order_id = ? ORDER BY seq ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }
}
