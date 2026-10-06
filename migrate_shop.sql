-- ============================================================
-- SHOP STORE FRONT - SCHEMA MIGRATION (PHASE 1, REV 4)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1) PRODUCTS
CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(64) NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  product_type ENUM('physical') NOT NULL DEFAULT 'physical',
  price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  product_pv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  pv_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  image_url VARCHAR(255) NULL,
  short_description VARCHAR(255) NULL,
  description TEXT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_products_status (status),
  INDEX idx_products_name (name)
) ENGINE=InnoDB;

-- 2) CARTS
CREATE TABLE IF NOT EXISTS carts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  status ENUM('active','abandoned','converted') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_carts_member_status (member_id, status)
) ENGINE=InnoDB;

-- 3) CART ITEMS
CREATE TABLE IF NOT EXISTS cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_pv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_product (cart_id, product_id),
  FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_cart_items_cart (cart_id)
) ENGINE=InnoDB;

-- 4) SHOP ORDERS
CREATE TABLE IF NOT EXISTS shop_orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL UNIQUE,
  member_id INT UNSIGNED NOT NULL,
  total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_pv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  payment_method ENUM('ewallet','gcash','maya','usdt_trc20','usdt_bep20') NOT NULL DEFAULT 'ewallet',
  idempotency_key VARCHAR(128) NULL UNIQUE,
  status ENUM('pending','payment_review','payment_failed','paid','packing','ready_to_ship','shipped','out_for_delivery','delivery_failed','returned_to_sender','delivered','completed','cancelled','on_hold') NOT NULL DEFAULT 'pending',
  previous_status ENUM('pending','payment_review','payment_failed','paid','packing','ready_to_ship','shipped','out_for_delivery','delivery_failed','returned_to_sender','delivered','completed','cancelled') NULL,
  payment_deadline DATETIME NULL,
  correction_deadline DATETIME NULL,
  completion_due_at DATETIME NULL,
  hold_deadline DATETIME NULL,
  hold_owner_id INT UNSIGNED NULL,
  hold_reason VARCHAR(160) NULL,
  billing_name VARCHAR(120) NOT NULL,
  billing_phone VARCHAR(40) NULL,
  billing_email VARCHAR(120) NULL,
  shipping_name VARCHAR(120) NOT NULL,
  shipping_phone VARCHAR(40) NULL,
  shipping_address VARCHAR(255) NOT NULL,
  shipping_city VARCHAR(80) NULL,
  shipping_province VARCHAR(80) NULL,
  shipping_postcode VARCHAR(20) NULL,
  shipping_country VARCHAR(2) NULL DEFAULT 'PH',
  notes_member VARCHAR(500) NULL,
  notes_admin VARCHAR(500) NULL,
  terms_version VARCHAR(16) NULL,
  terms_accepted_at DATETIME NULL,
  paid_by INT UNSIGNED NULL,
  paid_at DATETIME NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  packed_by INT UNSIGNED NULL,
  packed_at DATETIME NULL,
  shipped_by INT UNSIGNED NULL,
  shipped_at DATETIME NULL,
  delivered_by INT UNSIGNED NULL,
  delivered_at DATETIME NULL,
  completed_by INT UNSIGNED NULL,
  completed_at DATETIME NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_at DATETIME NULL,
  cancelled_reason VARCHAR(160) NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (packed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (shipped_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (delivered_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (hold_owner_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_shop_orders_member_status (member_id, status, created_at),
  INDEX idx_shop_orders_status_deadlines (status, payment_deadline, correction_deadline, completion_due_at),
  INDEX idx_shop_orders_idem (idempotency_key)
) ENGINE=InnoDB;

-- 5) SHOP ORDER ITEMS
CREATE TABLE IF NOT EXISTS shop_order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(160) NOT NULL,
  product_sku VARCHAR(64) NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  unit_pv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_pv DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  reservation_state ENUM('reserved','committed','deducted','released') NOT NULL DEFAULT 'reserved',
  deducted_at DATETIME NULL,
  released_at DATETIME NULL,
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  INDEX idx_shop_order_items_order (order_id),
  INDEX idx_shop_order_items_product (product_id)
) ENGINE=InnoDB;

-- 6) SHOP ORDER EVENTS
CREATE TABLE IF NOT EXISTS shop_order_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  actor_type ENUM('customer','admin','system') NOT NULL DEFAULT 'system',
  actor_id INT UNSIGNED NULL,
  reason_code VARCHAR(40) NULL,
  note VARCHAR(500) NULL,
  meta_json JSON NULL,
  ip VARCHAR(45) NULL,
  ua VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_shop_order_events_order_created (order_id, created_at)
) ENGINE=InnoDB;

-- 7) SHOP SHIPMENTS
CREATE TABLE IF NOT EXISTS shop_shipments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  reship_of INT UNSIGNED NULL,
  seq TINYINT UNSIGNED NOT NULL DEFAULT 1,
  state ENUM('draft','active','closed','void') NOT NULL DEFAULT 'active',
  courier VARCHAR(40) NULL,
  tracking_number VARCHAR(80) NULL,
  courier_link VARCHAR(255) NULL,
  handoff_at DATETIME NULL,
  delivered_at DATETIME NULL,
  rts_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  response_deadline DATETIME NULL,
  closed_at DATETIME NULL,
  closed_reason VARCHAR(80) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (reship_of) REFERENCES shop_shipments(id) ON DELETE SET NULL,
  INDEX idx_shop_shipments_order_seq (order_id, seq)
) ENGINE=InnoDB;

-- 8) SHOP PAYMENT PROOFS
CREATE TABLE IF NOT EXISTS shop_payment_proofs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  attempt_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
  file_path VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NULL,
  size_bytes INT UNSIGNED NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  reject_reason VARCHAR(160) NULL,
  amount_received DECIMAL(12,2) NULL,
  ref_no VARCHAR(64) NULL,
  ip VARCHAR(45) NULL,
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_shop_payment_proofs_order_attempt (order_id, attempt_no)
) ENGINE=InnoDB;

-- 9) SHOP REFUNDS
CREATE TABLE IF NOT EXISTS shop_refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  cause ENUM('admin_cancel','delivery_failure','customer_dispute','other') NOT NULL DEFAULT 'admin_cancel',
  method ENUM('ewallet','cash','bank','manual') NOT NULL DEFAULT 'ewallet',
  status ENUM('open','approved','processed','denied','closed') NOT NULL DEFAULT 'open',
  requested_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  processed_by INT UNSIGNED NULL,
  processed_at DATETIME NULL,
  note VARCHAR(500) NULL,
  ledger_ref_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE RESTRICT,
  FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_shop_refunds_order_status (order_id, status)
) ENGINE=InnoDB;

-- 10) EWALLET LEDGER - add shop_order ref_type
ALTER TABLE ewallet_ledger
  MODIFY COLUMN ref_type ENUM('commission','payout','reactivation','transfer','topup','registration','shop_order') NULL;

-- 11) SETTINGS
INSERT INTO settings (key_name, value) VALUES
  ('shop_enabled','1'),
  ('shop_payment_deadline_hours','24'),
  ('shop_correction_window_hours','24'),
  ('shop_max_proof_attempts','3'),
  ('shop_report_window_days','5'),
  ('shop_max_delivery_attempts','3')
ON DUPLICATE KEY UPDATE value = VALUES(value);

SET FOREIGN_KEY_CHECKS = 1;
