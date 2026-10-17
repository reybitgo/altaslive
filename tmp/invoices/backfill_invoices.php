<?php

/**
 * One-time backfill of immutable invoice snapshots.
 *
 * Issues a frozen invoice for every order that is currently paid-or-beyond
 * (or on hold from such a state) but predates the snapshot feature, so the
 * invoice pages stop falling back to live preview builds for historical
 * orders. Safe to re-run: ShopInvoice::issue() skips any order that already
 * has a non-void row.
 *
 * Run AFTER applying migrate_invoices.sql (or on a schema that already has
 * install.sql with the shop_invoices tables):
 *
 *   php tmp/invoices/backfill_invoices.php
 *   php tmp/invoices/backfill_invoices.php --dry-run
 *   php tmp/invoices/backfill_invoices.php --verbose
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../core/helpers.php';

spl_autoload_register(function (string $class): void {
    foreach ([__DIR__ . '/../../models/', __DIR__ . '/../../core/'] as $dir) {
        $file = $dir . $class . '.php';
        if (file_exists($file)) { require_once $file; return; }
    }
});

$dryRun  = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
$verbose = in_array('--verbose', $_SERVER['argv'] ?? [], true);

$pdo = db();

// Orders that were validly paid at some point and are still in a paid-or-
// beyond state. on_hold is included only when its frozen resume target was
// one of those; payment_review/payment_failed/cancelled are excluded because
// a pre-feature order in those states either never had a valid payment or
// had it reverted — nothing to invoice.
$paidStates = [
    'paid', 'packing', 'ready_to_ship', 'shipped', 'out_for_delivery',
    'delivery_failed', 'returned_to_sender', 'delivered', 'completed',
];
$inList = "'" . implode("','", $paidStates) . "'";

$candidates = $pdo->prepare(
    "SELECT o.id, o.order_no, o.status
       FROM shop_orders o
      WHERE (
              o.status IN ($inList)
              OR (o.status = 'on_hold' AND o.previous_status IN ($inList))
            )
        AND NOT EXISTS (SELECT 1 FROM shop_invoices si WHERE si.order_id = o.id)
      ORDER BY o.id"
);
$candidates->execute();
$rows = $candidates->fetchAll();

$total = count($rows);
echo ($dryRun ? '[dry-run] ' : '') . "Candidates: {$total} order(s) without a snapshot.\n";

$issued = 0;
$failed = 0;
foreach ($rows as $row) {
    $orderId = (int) $row['id'];
    try {
        if ($dryRun) {
            echo "  would issue INV-{$row['order_no']} for order #{$orderId} ({$row['status']})\n";
            continue;
        }
        $invoiceId = ShopInvoice::issue($orderId, $pdo, 'system', null);
        if ($invoiceId !== null) {
            $issued++;
            $no = (string) $pdo->query("SELECT invoice_no FROM shop_invoices WHERE id = {$invoiceId}")->fetchColumn();
            if ($verbose) {
                echo "  issued {$no} for order #{$orderId} ({$row['status']})\n";
            }
        } else {
            $failed++;
            echo "  SKIPPED order #{$orderId} — issue() returned nothing\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "  FAILED order #{$orderId}: " . $e->getMessage() . "\n";
    }
}

echo $dryRun
    ? "Dry run complete — nothing written.\n"
    : "Done: {$issued} issued, {$failed} failed, {$total} candidate(s).\n";
exit($failed > 0 ? 1 : 0);
