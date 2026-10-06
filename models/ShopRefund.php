<?php
class ShopRefund
{
    public static function hasOpen(int $orderId): bool
    {
        $st = db()->prepare("SELECT COUNT(*) FROM shop_refunds WHERE order_id = ? AND status = 'open'");
        $st->execute([$orderId]);
        return (int)$st->fetchColumn() > 0;
    }
    public static function forOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_refunds WHERE order_id = ? ORDER BY created_at ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }
}
