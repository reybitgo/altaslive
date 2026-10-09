<?php
/**
 * @file   views/partials/order_status_badge.php
 * @brief  The single status pill used by member + admin pages (§5.7).
 *         Expects: $order (array with status, created_at).
 */
?>
<?= shop_status_badge((string) $order['status'], $order['created_at'] ?? null) ?>
