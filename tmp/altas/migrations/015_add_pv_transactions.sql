-- Migration 015: PV transaction ledger and package PV flow
-- Phase 2 of PV-centered architecture adoption.
-- Records PV movement without changing existing peso-based commission logic.

CREATE TABLE IF NOT EXISTS pv_transactions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         INT UNSIGNED NOT NULL COMMENT 'Member whose PV balance is affected',
  type            ENUM(
                    'package_personal',
                    'package_group',
                    'product_personal',
                    'product_group',
                    'binary_left',
                    'binary_right',
                    'binary_paired',
                    'binary_flushed'
                  ) NOT NULL COMMENT 'Type of PV movement',
  amount          DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'PV amount added (always positive)',
  source_user_id  INT UNSIGNED NULL COMMENT 'The member whose action generated this PV',
  source_type     ENUM('registration','activation','repeat_purchase') NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_type (user_id, type),
  INDEX idx_created (created_at),
  FOREIGN KEY (user_id)        REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (source_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
