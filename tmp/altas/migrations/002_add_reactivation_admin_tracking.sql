-- ============================================================
-- Migration 002: Add Admin Tracking to Reactivations Table
-- ============================================================
-- Required for Phase 4 external payment confirmation flow.
-- Run after Phase 4 deploy if reactivations table already exists.

ALTER TABLE reactivations
  ADD COLUMN processed_by INT UNSIGNED NULL AFTER admin_note,
  ADD COLUMN processed_at TIMESTAMP NULL AFTER processed_by;

-- Add FK if not already present (safe to run multiple times)
SET @constraint_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'reactivations'
    AND CONSTRAINT_NAME = 'fk_reactivations_processed_by'
);

SET @sql := IF(@constraint_exists = 0,
  'ALTER TABLE reactivations ADD CONSTRAINT fk_reactivations_processed_by FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
