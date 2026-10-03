-- Migration 016: Drop total_package_pv column
-- Corrects Phase 2 semantics: members do not retain PV from their own package purchase.
-- Package PV flows up as Group PV only.

ALTER TABLE users DROP COLUMN total_package_pv;
