-- ============================================================
--  SHOP SCHEMA FIXUPS (follow-up to migrate_shop.sql, rev 4)
--  Aligns the phase-1 schema with the plan DDL
--  (tmp/shop/CART_AND_ADMIN_PRODUCTS_PLAN_REVISED.md §2.3).
--
--  Idempotent: every ALTER is information_schema-guarded
--  (same PREPARE/EXECUTE style as migrate_usdt_bep20.sql).
--  Run AFTER migrate_shop.sql; safe to run twice.
-- ============================================================

-- ── Helper: guarded ADD COLUMN ──────────────────────────────────────────────
-- (repeated per column; MySQL has no "ADD COLUMN IF NOT EXISTS" in 8.0)

-- 1) shop_order_events.source — timer/carrier event provenance.
--    Required by the expiry cron (every timer event must carry source='timer')
--    and by the plan's actor/source matrix (§2.3 table 5).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'shop_order_events'
      AND COLUMN_NAME = 'source'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_order_events ADD COLUMN source ENUM(''ui'',''api'',''timer'',''carrier'',''system'') NOT NULL DEFAULT ''ui'' AFTER actor_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) shop_order_items.reservation_state — add the missing 'written_off' value
--    (plan §0.3: reserved, committed, deducted, released, written_off).
ALTER TABLE shop_order_items
  MODIFY COLUMN reservation_state
  ENUM('reserved','committed','deducted','released','written_off') NOT NULL DEFAULT 'reserved';

-- 3) shop_shipments — POD + courier-fault fields for T15/T16/T18
--    (markDelivered needs pod_receiver; markDeliveryFailed needs fault + fail_reason;
--    the detail view needs expected_delivery_at and the label/POD images).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_shipments' AND COLUMN_NAME = 'fault'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_shipments
       ADD COLUMN fault ENUM(''customer'',''carrier'',''shop'') NULL AFTER attempts,
       ADD COLUMN fail_reason VARCHAR(160) NULL AFTER fault,
       ADD COLUMN expected_delivery_at DATETIME NULL AFTER handoff_at,
       ADD COLUMN out_for_delivery_at DATETIME NULL AFTER expected_delivery_at,
       ADD COLUMN pod_receiver VARCHAR(120) NULL AFTER delivered_at,
       ADD COLUMN pod_image VARCHAR(255) NULL AFTER pod_receiver,
       ADD COLUMN report_window_end DATETIME NULL AFTER pod_image',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) shop_orders.payment_reference — buyer-declared transfer reference.
--    Uniqueness across orders is checked in code at T5 (markPaid), not by a
--    DB constraint, so a cancelled order's reference stays traceable (§2.3).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'payment_reference'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_orders ADD COLUMN payment_reference VARCHAR(40) NULL AFTER payment_method',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) shop_refunds — the "closed only by a receipt" rule needs a place to live:
--    receipt_ref is mandatory to reach state='paid'; promised_at is the date
--    shown to the buyer (§2.3 table 8).
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_refunds' AND COLUMN_NAME = 'receipt_ref'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_refunds
       ADD COLUMN receipt_ref VARCHAR(80) NULL AFTER status,
       ADD COLUMN promised_at DATETIME NULL AFTER receipt_ref,
       ADD COLUMN destination VARCHAR(120) NULL AFTER cause',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 6) shop_shipments.tracking_number index (plan §2.3 table 6)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_shipments' AND INDEX_NAME = 'idx_tracking'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE shop_shipments ADD INDEX idx_tracking (tracking_number)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7) Address snapshot alias: the plan DDL (§2.3) names the postal column
--    shipping_postal; rev-4 of migrate_shop.sql created shipping_postcode.
--    Keep the plan name; the old column becomes an unused alias.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_orders' AND COLUMN_NAME = 'shipping_postal'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_orders ADD COLUMN shipping_postal VARCHAR(20) NULL AFTER shipping_postcode',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7b) shop_payment_proofs — plan DDL (§2.3 table 7) carries the buyer's claim
--      (amount_sent, transfer_date) and the uploader; rev-4 created only
--      mime/size columns and no uploaded_by.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_payment_proofs' AND COLUMN_NAME = 'amount_sent'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE shop_payment_proofs
       ADD COLUMN amount_sent DECIMAL(12,2) NULL AFTER ref_no,
       ADD COLUMN transfer_date DATE NULL AFTER amount_sent,
       ADD COLUMN uploaded_by INT UNSIGNED NULL AFTER transfer_date',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7c) proof_image rename: rev-4 called it file_path; the plan (§2.3 table 7)
--     calls it proof_image — rename so model and column agree.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_payment_proofs' AND COLUMN_NAME = 'file_path'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE shop_payment_proofs CHANGE COLUMN file_path proof_image VARCHAR(255) NOT NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 7d) reference_no rename: rev-4 called it ref_no VARCHAR(64); the plan
--     (§2.3 table 7) calls it reference_no VARCHAR(40) — same name the
--     buyer-facing form (views/member/shop_order.php) already posts.
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_payment_proofs' AND COLUMN_NAME = 'ref_no'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE shop_payment_proofs CHANGE COLUMN ref_no reference_no VARCHAR(40) NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 8) Widened idempotency_key to match the model's sha1 key (40 chars) with headroom
ALTER TABLE shop_orders
  MODIFY COLUMN idempotency_key VARCHAR(64) NULL;

-- Sanity: the 6 shop settings must exist (migrate_shop.sql step 11 seeds them;
-- this re-seed is a no-op on an existing row).
INSERT INTO settings (key_name, value) VALUES
  ('shop_enabled','1'),
  ('shop_payment_deadline_hours','24'),
  ('shop_correction_window_hours','24'),
  ('shop_max_proof_attempts','3'),
  ('shop_report_window_days','5'),
  ('shop_max_delivery_attempts','3')
ON DUPLICATE KEY UPDATE value = VALUES(value);
