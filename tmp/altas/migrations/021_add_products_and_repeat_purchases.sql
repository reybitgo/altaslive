-- Migration 021: Phase 5 — Products & Repeat Purchases PV

CREATE TABLE IF NOT EXISTS products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(120)     NOT NULL,
  price            DECIMAL(12,2)    NOT NULL,
  pv_value         DECIMAL(14,2)    NOT NULL,
  image_url        VARCHAR(255)     NULL DEFAULT NULL COMMENT 'Product image path relative to uploads/',
  short_description VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Short description shown on product cards',
  description      TEXT             NULL DEFAULT NULL COMMENT 'Full description shown in product detail modal',
  status           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS repeat_purchases (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id     INT UNSIGNED     NOT NULL,
  product_id    INT UNSIGNED     NOT NULL,
  quantity      INT UNSIGNED     NOT NULL DEFAULT 1,
  total_pv      DECIMAL(14,2)    NOT NULL,
  total_price   DECIMAL(12,2)    NOT NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  approved_by   INT UNSIGNED     NULL,
  approved_at   TIMESTAMP        NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id)  REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  FOREIGN KEY (approved_by) REFERENCES users(id)   ON DELETE SET NULL
) ENGINE=InnoDB;

-- Phase 5 gate: minimum personal PV required to earn repeat-purchase indirect/PV bonuses.
-- Default 0 means no gate until explicitly configured.
INSERT INTO settings (key_name, value) VALUES ('personal_pv_requirement', '0.0000')
ON DUPLICATE KEY UPDATE value = VALUES(value);
