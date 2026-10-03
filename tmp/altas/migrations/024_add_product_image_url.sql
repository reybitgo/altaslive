-- Migration 024: Add product image support

ALTER TABLE products
  ADD COLUMN image_url VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'Product image path relative to uploads/';
