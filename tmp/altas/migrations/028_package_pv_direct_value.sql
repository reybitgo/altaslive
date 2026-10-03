-- Migration 028: Change package_pv_rate from a percentage of entry_fee
-- to a direct absolute PV value.
--
-- The recommended convention uses pv_per_peso_rate = 1000 so that
-- a P10,000 package stores Package PV = 10 (small, clean numbers).
--
-- Package PV is now stored directly; the pv_per_peso_rate setting
-- converts PV to peso for all bonus payouts.
--
-- Before: package_pv_rate = 100 (meaning 100% of entry_fee)
-- After:  package_pv_rate = 10  (absolute PV value)
--
-- Formula conversion: new_value = old_value x entry_fee / 100 / 1000

ALTER TABLE packages
  MODIFY COLUMN package_pv_rate DECIMAL(14,2) NOT NULL DEFAULT 10.00
    COMMENT 'Absolute PV amount (not a percentage) — basis for direct/indirect/DFI/binary PV';

UPDATE packages SET package_pv_rate = (package_pv_rate * entry_fee / 100) / 1000;
