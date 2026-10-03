-- ============================================================
-- Migration: Add multi-method payout support
-- Run once via phpMyAdmin or MySQL CLI
-- ============================================================

-- 1. Add payout account fields to users profile
ALTER TABLE users
  ADD COLUMN maya_number   VARCHAR(20)  NULL AFTER gcash_number,
  ADD COLUMN usdt_address  VARCHAR(100) NULL AFTER maya_number;

-- 2. Add method + account columns to payout_requests
ALTER TABLE payout_requests
  ADD COLUMN payout_method  ENUM('gcash','maya','usdt') NOT NULL DEFAULT 'gcash' AFTER gcash_number,
  ADD COLUMN payout_account VARCHAR(100) NOT NULL DEFAULT '' AFTER payout_method;

-- 3. Migrate existing gcash_number values into payout_account (no data loss)
UPDATE payout_requests
  SET payout_account = gcash_number
  WHERE payout_account = '' AND gcash_number != '';

-- 4. Drop the now-redundant gcash_number column from payout_requests
ALTER TABLE payout_requests DROP COLUMN gcash_number;

-- ============================================================
-- RECOVERY (run if some completed rows still have blank
-- payout_account after Step 3 — recovers from ledger notes)
-- ============================================================
-- The ewallet_ledger note stores: "Payout via GCash 09XXXXXXXXX"
-- This extracts the account number from that note.
UPDATE payout_requests pr
  JOIN ewallet_ledger el ON el.ref_id = pr.id AND el.ref_type = 'payout'
  SET pr.payout_account = TRIM(SUBSTRING(el.note, LOCATE(' ', el.note, LOCATE(' ', el.note) + 1) + 1))
  WHERE pr.payout_account = ''
    AND el.note LIKE 'Payout via %'
    AND TRIM(SUBSTRING(el.note, LOCATE(' ', el.note, LOCATE(' ', el.note) + 1) + 1)) != '';

-- ============================================================
-- Migration Part 2: Service fees + USDT rate/amount columns
-- Run once via phpMyAdmin or MySQL CLI
-- ============================================================

-- 5. Add fee + USDT tracking columns to payout_requests
ALTER TABLE payout_requests
  ADD COLUMN service_fee_pct   DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER payout_account,
  ADD COLUMN service_fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER service_fee_pct,
  ADD COLUMN usdt_rate          DECIMAL(12,4) NOT NULL DEFAULT 0.00 AFTER service_fee_amount,
  ADD COLUMN usdt_gas_fee       DECIMAL(10,4) NOT NULL DEFAULT 0.00 AFTER usdt_rate,
  ADD COLUMN usdt_amount        DECIMAL(12,4) NOT NULL DEFAULT 0.00 AFTER usdt_gas_fee;

-- 6. Seed service fee + gas fee settings
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('service_fee_gcash', '0'),
  ('service_fee_maya',  '0'),
  ('service_fee_usdt',  '5'),
  ('usdt_gas_fee',      '2.50');
-- ============================================================
-- Migration Part 3: Admin toggle for GCash / Maya payout methods
-- Run once via phpMyAdmin or MySQL CLI
-- ============================================================

-- 7. Add gcash_enabled and maya_enabled settings (default enabled for backward compatibility)
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('gcash_enabled', '1'),
  ('maya_enabled',  '1');