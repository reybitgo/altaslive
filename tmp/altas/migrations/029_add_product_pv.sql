-- Migration 029: Add product_pv column — the base PV for a product.
-- pv_value becomes a percentage of product_pv (was absolute PV before).
-- Existing products: product_pv = old pv_value, pv_value = 100.00
-- New effective PV = product_pv × (pv_value / 100)

ALTER TABLE products
  ADD COLUMN product_pv DECIMAL(14,2) NOT NULL DEFAULT 0.00
    COMMENT 'Base PV value for this product'
  AFTER price;

ALTER TABLE products
  MODIFY COLUMN pv_value DECIMAL(14,2) NOT NULL DEFAULT 100.00
    COMMENT 'Percentage of product_pv that becomes effective PV';

UPDATE products SET product_pv = pv_value, pv_value = 100.00;
