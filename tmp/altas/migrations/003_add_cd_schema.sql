-- Migration: Add Commission-Deduct (CD) schema
-- Date: 2026-06-03

-- CD status tracking per user
CREATE TABLE IF NOT EXISTS user_cd_status (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    target_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    filled_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status          ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    assigned_by     INT NOT NULL,
    assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME NULL,
    cancelled_at    DATETIME NULL,
    notes           TEXT NULL,
    INDEX idx_user_active (user_id, status),
    INDEX idx_assigned_at (assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CD ledger: audit trail of commission splits
CREATE TABLE IF NOT EXISTS cd_ledger (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    cd_status_id        INT NOT NULL,
    commission_id       INT NULL,
    type                ENUM('pairing','direct_referral','indirect_referral') NOT NULL,
    gross_amount        DECIMAL(12,2) NOT NULL,
    cd_amount           DECIMAL(12,2) NOT NULL,
    withdrawable_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    source_user_id      INT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_cd (user_id, cd_status_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fast-lookup flag on users table
ALTER TABLE users ADD COLUMN cd_active TINYINT(1) NOT NULL DEFAULT 0;
