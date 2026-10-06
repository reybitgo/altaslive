<?php
class ShopOrderEvent
{
    public static function log(int $orderId, ?string $from, string $to, string $actorType, ?int $actorId = null, array $opts = []): int
    {
        $pdo = db();
        $st = $pdo->prepare("INSERT INTO shop_order_events (order_id, from_status, to_status, actor_type, actor_id, reason_code, note, meta_json, ip, ua) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $meta = $opts['meta_json'] ?? null;
        if (is_array($meta)) {
            $meta = json_encode($meta);
        }
        $st->execute([
            $orderId,
            $from,
            $to,
            $actorType,
            $actorId,
            $opts['reason_code'] ?? null,
            $opts['note'] ?? null,
            $meta,
            $opts['ip'] ?? null,
            $opts['ua'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function forOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_order_events WHERE order_id = ? ORDER BY created_at ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }

    public static function recent(int $limit = 50): array
    {
        $st = db()->prepare("SELECT * FROM shop_order_events ORDER BY created_at DESC, id DESC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
