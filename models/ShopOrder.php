<?php
class ShopOrder
{
    public const STATUSES = ['pending','payment_review','payment_failed','paid','packing','ready_to_ship','shipped','out_for_delivery','delivery_failed','returned_to_sender','delivered','completed','cancelled','on_hold'];
    public const TERMINAL = ['completed','cancelled'];

    public static function find(int $id): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_orders WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
    public static function findWithItems(int $id): ?array
    {
        $order = self::find($id);
        if (!$order) return null;
        $st = db()->prepare("SELECT oi.* FROM shop_order_items oi WHERE oi.order_id = ?");
        $st->execute([$id]);
        $order['items'] = $st->fetchAll();
        return $order;
    }
}
