<?php
class ShopPaymentProof
{
    public static function latestFor(int $orderId): ?array
    {
        $st = db()->prepare("SELECT * FROM shop_payment_proofs WHERE order_id = ? ORDER BY attempt_no DESC, id DESC LIMIT 1");
        $st->execute([$orderId]);
        return $st->fetch() ?: null;
    }
    public static function byOrder(int $orderId): array
    {
        $st = db()->prepare("SELECT * FROM shop_payment_proofs WHERE order_id = ? ORDER BY attempt_no ASC, id ASC");
        $st->execute([$orderId]);
        return $st->fetchAll();
    }
    public static function add(int $orderId, string $filePath, array $opts = []): int
    {
        $pdo = db();
        $latest = self::latestFor($orderId);
        $attempt = $latest ? ((int)$latest['attempt_no'] + 1) : 1;
        $st = $pdo->prepare("INSERT INTO shop_payment_proofs (order_id, attempt_no, file_path, mime, size_bytes, ip) VALUES (?, ?, ?, ?, ?, ?)");
        $st->execute([$orderId, $attempt, $filePath, $opts['mime'] ?? null, $opts['size_bytes'] ?? null, $opts['ip'] ?? null]);
        return (int)$pdo->lastInsertId();
    }
}
