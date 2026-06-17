-- ============================================================
--  MIGRATION: Add USDT BEP20 + rename TRC20 payout columns
--  Run: mysql -u USER -p DATABASE < migrate_usdt_bep20.sql
-- ============================================================

-- 1. Rename the legacy TRC20 address column to be explicit
SET @rename_col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'usdt_address'
);
SET @sql1 = IF(@rename_col > 0,
    'ALTER TABLE users CHANGE COLUMN usdt_address usdt_trc20_address VARCHAR(100) NULL',
    'SELECT 1'
);
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. Add the BEP20 address column if it does not exist yet
SET @bep20_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'usdt_bep20_address'
);
SET @sql2 = IF(@bep20_exists = 0,
    'ALTER TABLE users ADD COLUMN usdt_bep20_address VARCHAR(100) NULL AFTER usdt_trc20_address',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Rename legacy TRC20 payout columns
ALTER TABLE payout_requests
  CHANGE COLUMN usdt_rate      usdt_trc20_rate     DECIMAL(12,4) NOT NULL DEFAULT 0.00,
  CHANGE COLUMN usdt_gas_fee   usdt_trc20_gas_fee  DECIMAL(10,4) NOT NULL DEFAULT 0.00,
  CHANGE COLUMN usdt_amount    usdt_trc20_amount   DECIMAL(12,4) NOT NULL DEFAULT 0.00;

-- 4. Add BEP20 payout columns
SET @bep20_rate_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'payout_requests'
      AND COLUMN_NAME = 'usdt_bep20_rate'
);
SET @sql4 = IF(@bep20_rate_exists = 0,
    'ALTER TABLE payout_requests
      ADD COLUMN usdt_bep20_rate     DECIMAL(12,4) NOT NULL DEFAULT 0.00 AFTER usdt_trc20_amount,
      ADD COLUMN usdt_bep20_gas_fee  DECIMAL(10,4) NOT NULL DEFAULT 0.00 AFTER usdt_bep20_rate,
      ADD COLUMN usdt_bep20_amount   DECIMAL(12,4) NOT NULL DEFAULT 0.00 AFTER usdt_bep20_gas_fee',
    'SELECT 1'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 5. Expand payout_method ENUM to use usdt_trc20
ALTER TABLE payout_requests MODIFY COLUMN payout_method ENUM('gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'gcash';

-- 6. Allow reactivation payments via USDT TRC20 / BEP20
ALTER TABLE reactivations MODIFY COLUMN payment_method ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20','admin') NOT NULL DEFAULT 'ewallet';
UPDATE reactivations SET payment_method = 'usdt_trc20' WHERE payment_method = 'usdt';

-- 7. Rename legacy TRC20 setting keys to be explicit
UPDATE settings SET key_name = 'usdt_trc20_address'  WHERE key_name = 'usdt_address';
UPDATE settings SET key_name = 'usdt_trc20_gas_fee'  WHERE key_name = 'usdt_gas_fee';
UPDATE settings SET key_name = 'service_fee_usdt_trc20' WHERE key_name = 'service_fee_usdt';

-- 8. Seed default settings for the new method
INSERT INTO settings (key_name, value) VALUES
  ('usdt_bep20_address',      ''),
  ('service_fee_usdt_bep20',  '5'),
  ('usdt_bep20_gas_fee',      '0.05')
ON DUPLICATE KEY UPDATE value = VALUES(value);
