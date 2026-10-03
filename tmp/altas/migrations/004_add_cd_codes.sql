-- Migration: Add CD flag to registration codes
-- Date: 2026-05-28

ALTER TABLE reg_codes ADD COLUMN is_cd TINYINT(1) NOT NULL DEFAULT 0;
