-- ============================================================
--  MIGRATION: Billing name / phone + delivery notes for the
--  member address book.
--  IDEMPOTENT — safe to run repeatedly on an existing database.
--  Adds the Billing name, Billing phone, and Delivery notes the
--  checkout Delivery details form also captures, so a saved address
--  can prefill them.
--  Run: mysql -u USER -p DATABASE < migrate_member_addresses_billing.sql
-- ============================================================

-- 1. billing_name
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'billing_name'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses ADD COLUMN billing_name VARCHAR(120) NULL AFTER barangay_code',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. billing_phone
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'billing_phone'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses ADD COLUMN billing_phone VARCHAR(40) NULL AFTER billing_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. notes_member
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'notes_member'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses ADD COLUMN notes_member VARCHAR(500) NULL AFTER billing_phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
