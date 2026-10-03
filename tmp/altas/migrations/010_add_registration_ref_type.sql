-- Migration 010: Add 'registration' to ewallet_ledger ref_type enum

ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup','registration') NULL;
