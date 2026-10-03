-- Migration 007: Add withdrawable_balance for non-withdrawable e-wallet tracking

ALTER TABLE users
  ADD COLUMN withdrawable_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00
  AFTER ewallet_balance;
