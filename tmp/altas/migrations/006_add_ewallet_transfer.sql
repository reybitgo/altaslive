-- Migration 006: E-Wallet Transfer, Admin Top-Up & Fee System

-- 1. Transfer transactions log
CREATE TABLE ewallet_transfers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sender_id     INT UNSIGNED NOT NULL,
  recipient_id  INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  fee           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount    DECIMAL(12,2) NOT NULL,
  status        ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sender_id)    REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 2. Admin manual top-ups log
CREATE TABLE ewallet_admin_topups (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id      INT UNSIGNED NOT NULL,
  recipient_id  INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  note          VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (admin_id)     REFERENCES users(id),
  FOREIGN KEY (recipient_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- 3. Expand ewallet_ledger ref_type to include transfers and topups
ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup') NULL;
