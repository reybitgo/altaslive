<?php

/**
 * One-time backfill of product SKU onto order items and frozen invoice lines.
 *
 * Cart::getItems() never selected products.sku, so every order placed before
 * the fix stored shop_order_items.product_sku = NULL, and ShopInvoice::issue()
 * froze that NULL into shop_invoice_items.sku. New orders capture the SKU
 * correctly; this repairs the rows already written.
 *
 * Two passes, both idempotent:
 *   1. shop_order_items.product_sku  < products.sku  (via product_id)
 *   2. shop_invoice_items.sku        < the order item at the same line_no
 *
 * Pass 2 writes only into a blank sku, so a frozen snapshot that already has
 * one is never touched. Matching is by line_no because issue() wrote the Nth
 * order item (ordered by id) as line_no N.
 *
 * Run:
 *   php tmp/invoices/backfill_invoice_skus.php
 *   php tmp/invoices/backfill_invoice_skus.php --dry-run
 */

require_once __DIR__ . '/../../config/db.php';

$dryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);

$pdo = db();

$countMissingOrderSku = (int) $pdo->query(
    "SELECT COUNT(*) FROM shop_order_items oi
      JOIN products p ON p.id = oi.product_id
     WHERE (oi.product_sku IS NULL OR oi.product_sku = '') AND p.sku IS NOT NULL"
)->fetchColumn();

$countMissingInvoiceSku = (int) $pdo->query(
    "SELECT COUNT(*) FROM shop_invoice_items ii
      JOIN shop_invoices inv ON inv.id = ii.invoice_id
      JOIN (SELECT oi.order_id, p.sku AS product_sku,
                   ROW_NUMBER() OVER (PARTITION BY oi.order_id ORDER BY oi.id) rn
              FROM shop_order_items oi
              JOIN products p ON p.id = oi.product_id) oi
        ON oi.order_id = inv.order_id AND oi.rn = ii.line_no
     WHERE (ii.sku IS NULL OR ii.sku = '') AND oi.product_sku IS NOT NULL"
)->fetchColumn();

echo ($dryRun ? '[dry-run] ' : '')
    . "Order items missing SKU: {$countMissingOrderSku}; "
    . "Invoice lines missing SKU: {$countMissingInvoiceSku}.\n";

if ($dryRun) {
    echo "Dry run complete — nothing written.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $orderStmt = $pdo->exec(
        "UPDATE shop_order_items oi
           JOIN products p ON p.id = oi.product_id
            SET oi.product_sku = p.sku
          WHERE (oi.product_sku IS NULL OR oi.product_sku = '') AND p.sku IS NOT NULL"
    );

    $invoiceStmt = $pdo->exec(
        "UPDATE shop_invoice_items ii
           JOIN shop_invoices inv ON inv.id = ii.invoice_id
           JOIN (SELECT order_id, product_sku,
                        ROW_NUMBER() OVER (PARTITION BY order_id ORDER BY id) rn
                   FROM shop_order_items) oi
             ON oi.order_id = inv.order_id AND oi.rn = ii.line_no
            SET ii.sku = oi.product_sku
          WHERE (ii.sku IS NULL OR ii.sku = '') AND oi.product_sku IS NOT NULL"
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Backfill failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Done: {$orderStmt} order item(s) and {$invoiceStmt} invoice line(s) updated.\n";
exit(0);
