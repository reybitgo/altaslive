-- Migration 019: PV-based binary pairing schema
-- Adds paired PV tracking and percentage-based pairing bonus.
-- Legacy count columns (left_count, right_count, pairs_paid, pairs_flushed,
-- pairs_paid_today, pairing_bonus, daily_pair_cap) are kept for reference only.

ALTER TABLE packages
  ADD COLUMN pairing_pv_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Pairing bonus = paired_pv * (pairing_pv_pct/100) * pv_per_peso_rate'
    AFTER pairing_bonus,
  ADD COLUMN daily_pair_pv_cap DECIMAL(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Max paired PV per member per day'
    AFTER daily_pair_cap;

ALTER TABLE users
  ADD COLUMN paired_pv DECIMAL(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Total paired PV lifetime'
    AFTER right_pv,
  ADD COLUMN paired_pv_today DECIMAL(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Paired PV today (midnight reset)'
    AFTER pairs_paid_today;

-- Backfill package pairing percentage from legacy fixed-peso bonus.
-- Uses the current pv_per_peso_rate so existing payouts stay equivalent at migration time.
SET @pv_rate = (SELECT CAST(value AS DECIMAL(10,4)) FROM settings WHERE key_name = 'pv_per_peso_rate');
SET @pv_rate = IFNULL(@pv_rate, 1.0000);

UPDATE packages
SET pairing_pv_pct = CASE
  WHEN entry_fee * (package_pv_rate / 100) * @pv_rate > 0
    THEN (pairing_bonus / (entry_fee * (package_pv_rate / 100) * @pv_rate)) * 100
  ELSE 0
END;

-- Backfill daily paired-PV cap from legacy count cap (count * own package PV).
UPDATE packages p
SET p.daily_pair_pv_cap = p.daily_pair_cap * p.entry_fee * (p.package_pv_rate / 100);

-- Backfill user leg PV and paired PV from legacy counts * own package PV.
UPDATE users u
JOIN packages p ON p.id = u.package_id
SET u.left_pv      = u.left_count      * p.entry_fee * (p.package_pv_rate / 100),
    u.right_pv     = u.right_count     * p.entry_fee * (p.package_pv_rate / 100),
    u.paired_pv    = (u.pairs_paid + u.pairs_flushed) * p.entry_fee * (p.package_pv_rate / 100),
    u.flushed_pv   = u.pairs_flushed   * p.entry_fee * (p.package_pv_rate / 100);
