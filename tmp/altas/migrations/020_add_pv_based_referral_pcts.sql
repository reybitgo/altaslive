-- Migration 020: Phase 4 — Direct & Indirect referral bonuses become % of Package PV

ALTER TABLE packages
  ADD COLUMN direct_ref_pv_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Direct referral bonus = package_pv * (direct_ref_pv_pct/100) * pv_per_peso_rate'
    AFTER direct_ref_bonus;

ALTER TABLE package_indirect_levels
  ADD COLUMN pv_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Indirect level bonus = package_pv * (pv_pct/100) * pv_per_peso_rate'
    AFTER bonus;

-- Backfill percentages from legacy fixed-peso amounts using the current pv_per_peso_rate.
SET @pv_rate = (SELECT CAST(value AS DECIMAL(10,4)) FROM settings WHERE key_name = 'pv_per_peso_rate');
SET @pv_rate = IFNULL(@pv_rate, 1.0000);

UPDATE packages
SET direct_ref_pv_pct = CASE
  WHEN entry_fee * (package_pv_rate / 100) * @pv_rate > 0
    THEN (direct_ref_bonus / (entry_fee * (package_pv_rate / 100) * @pv_rate)) * 100
  ELSE 0
END;

UPDATE package_indirect_levels il
JOIN packages p ON p.id = il.package_id
SET il.pv_pct = CASE
  WHEN p.entry_fee * (p.package_pv_rate / 100) * @pv_rate > 0
    THEN (il.bonus / (p.entry_fee * (p.package_pv_rate / 100) * @pv_rate)) * 100
  ELSE 0
END;
