-- Migration 017: Remove package PV flow
-- Corrects Phase 2: package purchases do NOT generate any PV.
-- PV flow is exclusively from product purchases (Phase 5).

-- Remove package PV rate from packages (no longer used)
ALTER TABLE packages DROP COLUMN package_pv_rate;

-- Clean up any package_group transactions created before this correction
DELETE FROM pv_transactions WHERE type = 'package_group';
