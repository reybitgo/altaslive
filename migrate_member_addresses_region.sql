-- ============================================================
--  MIGRATION: Region + Barangay for the member address book
--  IDEMPOTENT — safe to run repeatedly on an existing database.
--  Adds the Region / Barangay (and PSGC code) columns backing
--  the profile address-book picker and the checkout prefill.
--  Run: mysql -u USER -p DATABASE < migrate_member_addresses_region.sql
-- ============================================================

-- 1. region
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'region'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses ADD COLUMN region VARCHAR(80) NULL AFTER province',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. barangay
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'barangay'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses ADD COLUMN barangay VARCHAR(120) NULL AFTER city',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. PSGC codes (used to re-resolve the picker on edit)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'member_addresses'
      AND COLUMN_NAME = 'region_code'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE member_addresses
       ADD COLUMN region_code   VARCHAR(20) NULL,
       ADD COLUMN province_code VARCHAR(20) NULL,
       ADD COLUMN city_code     VARCHAR(20) NULL,
       ADD COLUMN barangay_code VARCHAR(20) NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
