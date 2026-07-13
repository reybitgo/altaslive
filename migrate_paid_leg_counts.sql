-- ============================================================
--  MIGRATION: Add left_count_paid / right_count_paid columns
--  Prevents CD-sourced members from contributing to pairing bonuses
--  Run: mysql -u USER -p DATABASE < migrate_paid_leg_counts.sql
-- ============================================================

-- 1. Add paid-only leg count columns
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'left_count_paid'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE users
      ADD COLUMN left_count_paid  INT UNSIGNED NOT NULL DEFAULT 0 AFTER right_count,
      ADD COLUMN right_count_paid INT UNSIGNED NOT NULL DEFAULT 0 AFTER left_count_paid',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Initialize to current totals (only on fresh migration)
SET @init_sql = IF(@col_exists = 0,
    'UPDATE users SET left_count_paid = left_count, right_count_paid = right_count',
    'SELECT 1'
);
PREPARE stmt2 FROM @init_sql;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;
