-- Extend reg_payment_method ENUM to support pending referral-link registrations
ALTER TABLE users
  MODIFY COLUMN reg_payment_method ENUM('code','ewallet','pending') NOT NULL DEFAULT 'code';
