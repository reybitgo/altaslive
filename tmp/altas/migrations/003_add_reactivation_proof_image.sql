-- ============================================================
-- Migration 003: Add proof_image to Reactivations Table
-- ============================================================
-- Required for Phase 4 external payment proof upload.
-- Run if reactivations table already exists.

ALTER TABLE reactivations
  ADD COLUMN proof_image VARCHAR(255) NULL AFTER admin_note;
