-- ============================================================
-- Migration 001: Add Income Capping & Reactivation Schema
-- ============================================================

-- 1. Add capping columns to packages (default values for new packages)
ALTER TABLE packages
  ADD COLUMN income_cap DECIMAL(12,2) NOT NULL DEFAULT 30000.00
    COMMENT 'Maximum lifetime binary earnings per cycle'
    AFTER direct_ref_bonus,
  ADD COLUMN reactivation_fee DECIMAL(12,2) NOT NULL DEFAULT 10000.00
    COMMENT 'Fee to reactivate after hitting cap'
    AFTER income_cap,
  ADD COLUMN reactivation_window_days TINYINT UNSIGNED NOT NULL DEFAULT 15
    COMMENT 'Days allowed to reactivate before permanent deactivation'
    AFTER reactivation_fee;

-- 2. Add member-level capping state to users
ALTER TABLE users
  ADD COLUMN binary_earned_this_cycle DECIMAL(12,2) NOT NULL DEFAULT 0.00
    COMMENT 'Cumulative binary earnings in current cycle'
    AFTER pairs_paid_today,
  ADD COLUMN cap_status ENUM('active','capped','perminact') NOT NULL DEFAULT 'active'
    COMMENT 'Current capping status'
    AFTER binary_earned_this_cycle,
  ADD COLUMN capped_at TIMESTAMP NULL
    COMMENT 'When member hit the income cap'
    AFTER cap_status,
  ADD COLUMN reactivation_window_expires TIMESTAMP NULL
    COMMENT 'Deadline to reactivate before permanent deactivation'
    AFTER capped_at,
  ADD COLUMN reactivation_count INT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'How many times member has reactivated'
    AFTER reactivation_window_expires,
  ADD COLUMN last_reactivated_at TIMESTAMP NULL
    COMMENT 'Most recent reactivation timestamp'
    AFTER reactivation_count;

-- 3. Add reactivation revenue tracking to company
-- (Uses existing payout_requests structure — reactivations are payments TO company)

-- 4. Create reactivation payments table (member pays company to reactivate)
CREATE TABLE reactivation_payments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  package_id    INT UNSIGNED NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  payment_method ENUM('gcash','maya','usdt','manual') NOT NULL DEFAULT 'manual',
  payment_ref   VARCHAR(100) NULL COMMENT 'External transaction reference',
  status        ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  processed_by  INT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  confirmed_at  TIMESTAMP NULL,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (package_id) REFERENCES packages(id),
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_user_status (user_id, status),
  INDEX idx_pending (status, created_at)
) ENGINE=InnoDB;

-- 5. Add indexes for performance
ALTER TABLE users ADD INDEX idx_cap_status (cap_status, capped_at);
ALTER TABLE users ADD INDEX idx_reactivation_window (reactivation_window_expires, cap_status);

-- 6. Update existing members: set cap_status based on current earnings
-- (Backfill: members who already exceeded cap get 'capped' status)
UPDATE users u
  JOIN packages p ON p.id = u.package_id
  SET u.cap_status = 'capped',
      u.capped_at = NOW(),
      u.reactivation_window_expires = DATE_ADD(NOW(), INTERVAL p.reactivation_window_days DAY)
  WHERE u.binary_earned_this_cycle >= p.income_cap
    AND u.cap_status = 'active'
    AND u.role = 'member';