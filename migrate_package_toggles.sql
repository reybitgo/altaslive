-- ============================================================
--  Package-level commission toggles + reg_codes.code_type
--  Run once: mysql -u root -p DATABASE < migrate_package_toggles.sql
-- ============================================================

ALTER TABLE packages
  ADD COLUMN indirect_referral_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status,
  ADD COLUMN dfi_enabled               TINYINT(1) NOT NULL DEFAULT 1 AFTER indirect_referral_enabled,
  ADD COLUMN pairing_enabled           TINYINT(1) NOT NULL DEFAULT 1 AFTER dfi_enabled;

-- Replace legacy is_cd flag with a code_type column
ALTER TABLE reg_codes
  ADD COLUMN code_type ENUM('registration','cd','upgrade') NOT NULL DEFAULT 'registration' AFTER is_cd;

-- Migrate existing data: is_cd=1  -> code_type='cd'
UPDATE reg_codes SET code_type = 'cd' WHERE is_cd = 1;

-- Remove legacy column
ALTER TABLE reg_codes DROP COLUMN is_cd;