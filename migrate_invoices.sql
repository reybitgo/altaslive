-- ============================================================
-- IMMUTABLE INVOICE SNAPSHOTS - SCHEMA MIGRATION
-- ============================================================
-- Creates the invoice snapshot tables from the invoice-freeze
-- feature. install.sql already carries these for fresh installs;
-- this file is for existing databases.
--
-- After applying, run the one-time backfill for orders that were
-- already paid before this migration:
--   php tmp/invoices/backfill_invoices.php
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS shop_invoices (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id          INT UNSIGNED NOT NULL,
  invoice_no        VARCHAR(32) NOT NULL,
  issued_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by_type    ENUM('system','admin') NOT NULL DEFAULT 'system',
  issued_by_id      INT UNSIGNED NULL,
  voided_at         DATETIME NULL,
  void_reason       VARCHAR(80) NULL,
  snapshot_version  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  order_no          VARCHAR(20) NOT NULL,
  order_created_at  DATETIME NULL,
  status_at_issue   VARCHAR(20) NOT NULL,
  seller_name       VARCHAR(160) NOT NULL DEFAULT '',
  seller_address    VARCHAR(500) NULL,
  seller_tin        VARCHAR(40) NULL,
  seller_phone      VARCHAR(40) NULL,
  seller_email      VARCHAR(120) NULL,
  seller_footer     VARCHAR(500) NULL,
  seller_vat_note   VARCHAR(500) NULL,
  currency          VARCHAR(8) NOT NULL DEFAULT 'PHP',
  billing_name      VARCHAR(120) NOT NULL DEFAULT '',
  billing_phone     VARCHAR(40) NULL,
  billing_email     VARCHAR(120) NULL,
  shipping_name     VARCHAR(120) NOT NULL DEFAULT '',
  shipping_phone    VARCHAR(40) NULL,
  shipping_address  VARCHAR(255) NULL,
  shipping_city     VARCHAR(80) NULL,
  shipping_province VARCHAR(80) NULL,
  shipping_postal   VARCHAR(20) NULL,
  shipping_country  VARCHAR(2) NULL,
  payment_method    VARCHAR(20) NOT NULL DEFAULT '',
  payment_reference VARCHAR(40) NULL,
  paid_at           DATETIME NULL,
  notes_member      VARCHAR(500) NULL,
  buyer_username    VARCHAR(60) NULL,
  subtotal          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total             DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount_paid       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shop_invoices_no (invoice_no),
  INDEX idx_shop_invoices_order (order_id, voided_at),
  FOREIGN KEY (order_id)     REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (issued_by_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shop_invoice_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id  INT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(160) NOT NULL,
  sku         VARCHAR(64) NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  amount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  INDEX idx_shop_invoice_items_invoice (invoice_id),
  FOREIGN KEY (invoice_id) REFERENCES shop_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
