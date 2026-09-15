-- Active: 1761477309529@@127.0.0.1@3306@u938213108_altas3_db
-- ============================================================
--  PACKAGE IMAGE UPLOAD — migration for existing databases
--  Run once: mysql -u root -p DATABASE < migrate_package_image.sql
-- ============================================================

ALTER TABLE packages
  ADD COLUMN image VARCHAR(200) NULL AFTER name;