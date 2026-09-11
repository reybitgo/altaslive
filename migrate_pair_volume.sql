-- ============================================================
--  MIGRATION: Volume-based binary pairing
--  Adds per-leg pair volume and matched-volume accounting columns.
--  Run: mysql -u USER -p DATABASE < migrate_pair_volume.sql
-- ============================================================

-- 1. Per-leg accumulated pair volume (pesos) contributed by paid members
SET @lpv_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'left_pair_volume'
);
SET @sql_lpv = IF(@lpv_exists = 0,
    'ALTER TABLE users ADD COLUMN left_pair_volume DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER right_count_paid',
    'SELECT 1'
);
PREPARE stmt_lpv FROM @sql_lpv;
EXECUTE stmt_lpv;
DEALLOCATE PREPARE stmt_lpv;

SET @rpv_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'right_pair_volume'
);
SET @sql_rpv = IF(@rpv_exists = 0,
    'ALTER TABLE users ADD COLUMN right_pair_volume DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER left_pair_volume',
    'SELECT 1'
);
PREPARE stmt_rpv FROM @sql_rpv;
EXECUTE stmt_rpv;
DEALLOCATE PREPARE stmt_rpv;

-- 2. Matched-volume accounting (pesos)
SET @pvp_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pairs_volume_paid'
);
SET @sql_pvp = IF(@pvp_exists = 0,
    'ALTER TABLE users ADD COLUMN pairs_volume_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pairs_paid_today',
    'SELECT 1'
);
PREPARE stmt_pvp FROM @sql_pvp;
EXECUTE stmt_pvp;
DEALLOCATE PREPARE stmt_pvp;

SET @pvf_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pairs_volume_flushed'
);
SET @sql_pvf = IF(@pvf_exists = 0,
    'ALTER TABLE users ADD COLUMN pairs_volume_flushed DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pairs_volume_paid',
    'SELECT 1'
);
PREPARE stmt_pvf FROM @sql_pvf;
EXECUTE stmt_pvf;
DEALLOCATE PREPARE stmt_pvf;

SET @pvt_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'pairs_volume_today'
);
SET @sql_pvt = IF(@pvt_exists = 0,
    'ALTER TABLE users ADD COLUMN pairs_volume_today DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER pairs_volume_flushed',
    'SELECT 1'
);
PREPARE stmt_pvt FROM @sql_pvt;
EXECUTE stmt_pvt;
DEALLOCATE PREPARE stmt_pvt;