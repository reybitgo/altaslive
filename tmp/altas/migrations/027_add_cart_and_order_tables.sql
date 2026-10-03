-- ============================================================
--  MIGRATION 027: Cart + order tables for repeat-purchase
--  redesign. Replaces the single-row repeat_purchases table
--  with a full cart + checkout + order model.
--  Run: mysql -u USER -p DATABASE < migrations/027_add_cart_and_order_tables.sql
-- ============================================================

-- 1. Add stock to products (absolute inventory, never mutated by orders)
ALTER TABLE products
  ADD COLUMN stock INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Total physical inventory';

-- 2. Add package-level personal PV requirement
ALTER TABLE packages
  ADD COLUMN personal_pv_requirement DECIMAL(14,2) NOT NULL DEFAULT 0.00
  COMMENT 'Minimum Personal PV an upline must have to receive Group/Binary PV from product purchases';

-- Seed existing packages from the old global setting (one-time only)
UPDATE packages SET personal_pv_requirement = COALESCE(
  (SELECT value FROM settings WHERE key_name = 'personal_pv_requirement'),
  '0.0000'
);

-- 3. Create carts table
CREATE TABLE carts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id  INT UNSIGNED NOT NULL,
  status     ENUM('active','abandoned','converted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Create cart_items table
CREATE TABLE cart_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id     INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  quantity    INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price  DECIMAL(12,2) NOT NULL,
  unit_pv     DECIMAL(14,2) NOT NULL,
  added_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  FOREIGN KEY (cart_id)    REFERENCES carts(id)    ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. Create repeat_purchase_orders table (replaces repeat_purchases)
CREATE TABLE repeat_purchase_orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id        INT UNSIGNED NOT NULL,
  total_pv         DECIMAL(14,2) NOT NULL,
  total_price      DECIMAL(12,2) NOT NULL,
  binary_position  ENUM('left','right') NOT NULL DEFAULT 'left' COMMENT 'Side used for buyer''s own leg PV placement',
  payment_method   ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20') NOT NULL,
  proof_image      VARCHAR(255) NULL,
  status           ENUM('pending','paid','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  approved_by      INT UNSIGNED NULL,
  approved_at      TIMESTAMP NULL,
  paid_at          TIMESTAMP NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id)   REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (approved_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

-- 6. Create repeat_purchase_order_items table
CREATE TABLE repeat_purchase_order_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  quantity     INT UNSIGNED NOT NULL,
  unit_price   DECIMAL(12,2) NOT NULL,
  unit_pv      DECIMAL(14,2) NOT NULL,
  total_price  DECIMAL(12,2) NOT NULL,
  total_pv     DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (order_id)   REFERENCES repeat_purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)              ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 7. Migrate existing repeat_purchases rows into new order tables
--    Old table has no proof_image column, so we insert NULL.
INSERT INTO repeat_purchase_orders
  (id, member_id, total_pv, total_price, binary_position, payment_method, proof_image, status, approved_by, approved_at, created_at)
SELECT
  rp.id, rp.member_id, rp.total_pv, rp.total_price, COALESCE(u.binary_position, 'left'), 'gcash',
  NULL AS proof_image,
  rp.status,
  rp.approved_by,
  rp.approved_at,
  rp.created_at
FROM repeat_purchases rp
JOIN users u ON u.id = rp.member_id;

INSERT INTO repeat_purchase_order_items
  (order_id, product_id, quantity, unit_price, unit_pv, total_price, total_pv)
SELECT
  rp.id, rp.product_id, rp.quantity,
  CASE WHEN rp.quantity > 0 THEN rp.total_price / rp.quantity ELSE COALESCE(p.price, 0) END,
  CASE WHEN rp.quantity > 0 THEN rp.total_pv / rp.quantity ELSE COALESCE(p.pv_value, 0) END,
  rp.total_price, rp.total_pv
FROM repeat_purchases rp
LEFT JOIN products p ON p.id = rp.product_id;

-- 8. Drop old table immediately
DROP TABLE repeat_purchases;

-- 9. Remove the global setting (moved to packages.personal_pv_requirement)
DELETE FROM settings WHERE key_name = 'personal_pv_requirement';
