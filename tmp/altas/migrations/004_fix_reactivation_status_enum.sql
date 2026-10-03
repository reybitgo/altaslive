-- ============================================================
-- Migration 004: Fix Reactivations Status Enum
-- ============================================================
-- Some older deployments have status ENUM('pending','completed','failed')
-- which causes "Data truncated" error when rejecting a reactivation.
-- The correct ENUM must include 'rejected', not 'failed'.
-- Run if your reactivations table status column lacks 'rejected'.

ALTER TABLE reactivations
  MODIFY COLUMN status ENUM('pending','completed','rejected') NOT NULL DEFAULT 'completed';
