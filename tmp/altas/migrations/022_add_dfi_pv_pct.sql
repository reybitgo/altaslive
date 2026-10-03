-- Migration 022: Phase 6 — Optional DFI basis as % of Package PV
-- If dfi_pv_pct > 0, daily fixed income = package_pv * (dfi_pv_pct/100) * pv_per_peso_rate.
-- If dfi_pv_pct = 0, the existing fixed daily_fixed_income amount is used.

ALTER TABLE packages
  ADD COLUMN dfi_pv_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Optional DFI as % of package PV; 0 falls back to daily_fixed_income'
    AFTER daily_fixed_income_days;
