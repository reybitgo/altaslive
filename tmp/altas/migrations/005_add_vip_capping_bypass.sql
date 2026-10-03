-- Migration 005: VIP Capping Bypass + Daily Cap Bypass
-- Adds per-user toggles for admin to bypass lifetime cap and daily pair cap

ALTER TABLE users
  ADD COLUMN capping_bypass TINYINT(1) NOT NULL DEFAULT 0
  AFTER cap_status;

ALTER TABLE users
  ADD COLUMN daily_cap_bypass TINYINT(1) NOT NULL DEFAULT 0
  AFTER capping_bypass;
