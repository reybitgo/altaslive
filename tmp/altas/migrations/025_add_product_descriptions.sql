-- Migration 025: Add product short and long descriptions

ALTER TABLE products
  ADD COLUMN short_description VARCHAR(255) NULL DEFAULT NULL COMMENT 'Short description shown on product cards',
  ADD COLUMN description TEXT NULL DEFAULT NULL COMMENT 'Full description shown in product detail modal';
