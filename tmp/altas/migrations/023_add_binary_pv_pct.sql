-- Migration 023: Separate binary PV allocation from package PV.
--
-- NOTE: As of migration 028, package_pv_rate stores absolute PV (not a %).
-- With the convention (pv_per_peso_rate=1000), values are small and clean.
-- Binary PV = Package PV x (binary_pv_pct / 100)
-- Default 20% on a P10,000 Starter (Package PV=10) allocates 2 PV to binary legs.

ALTER TABLE packages
  ADD COLUMN binary_pv_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00
    COMMENT 'Percentage of Package PV that becomes binary PV'
  AFTER package_pv_rate;
