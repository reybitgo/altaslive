-- Migration 009: Add e-wallet registration tracking columns
-- reg_code_id is already nullable (INT UNSIGNED NULL)

ALTER TABLE users
  ADD COLUMN reg_payment_method ENUM('code','ewallet') NOT NULL DEFAULT 'code'
  AFTER reg_code_id;

ALTER TABLE users
  ADD COLUMN reg_paid_by INT UNSIGNED NULL
  AFTER reg_payment_method;
