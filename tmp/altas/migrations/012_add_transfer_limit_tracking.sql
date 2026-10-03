-- Migration 012: Add e-wallet transfer limit tracking columns
-- These columns are reset by cron/fund_transfer_limit_reset.php
-- and incremented by Ewallet::transfer() for fast limit checks.

ALTER TABLE users
  ADD COLUMN ewallet_sent_today DECIMAL(12,2) NOT NULL DEFAULT 0.00
    AFTER cd_active;

ALTER TABLE users
  ADD COLUMN ewallet_sent_this_week DECIMAL(12,2) NOT NULL DEFAULT 0.00
    AFTER ewallet_sent_today;
