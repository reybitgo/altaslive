-- ════════════════════════════════════════════════════════════
--  Migration 030: Add product-level unilevel bonus support
-- ════════════════════════════════════════════════════════════

-- New table: stores 10 unilevel percentages per product
CREATE TABLE IF NOT EXISTS product_unilevel_levels (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED     NOT NULL,
  level      TINYINT UNSIGNED NOT NULL,
  pv_pct     DECIMAL(5,2)     NOT NULL DEFAULT 0.00
             COMMENT 'Unilevel product bonus = product_eff_pv * (pv_pct/100) * pv_per_peso_rate',
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  UNIQUE KEY uq_product_level (product_id, level)
) ENGINE=InnoDB;

-- Extend commissions.type ENUM to include unilevel_product
ALTER TABLE commissions
  MODIFY COLUMN type
    ENUM('pairing','direct_referral','indirect_referral','daily_fixed_income','unilevel_product')
    NOT NULL;

-- Extend cd_ledger.type ENUM to include unilevel_product
ALTER TABLE cd_ledger
  MODIFY COLUMN type
    ENUM('pairing','direct_referral','indirect_referral','unilevel_product')
    NOT NULL;

-- Global toggle setting (default enabled)
INSERT INTO settings (key_name, value) VALUES ('unilevel_product_enabled', '1')
  ON DUPLICATE KEY UPDATE value = '1';

-- Index for quick lookup by product
ALTER TABLE product_unilevel_levels
  ADD INDEX idx_product_level (product_id, level);
