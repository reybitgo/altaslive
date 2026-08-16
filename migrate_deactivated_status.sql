-- ============================================================
--  MIGRATION: Add 'deactivated' status to users
--  Run: mysql -u USER -p DATABASE < migrate_deactivated_status.sql
-- ============================================================

-- 1. Expand users.status ENUM to include 'deactivated'
ALTER TABLE users MODIFY COLUMN status ENUM('active','suspended','pending','deactivated') NOT NULL DEFAULT 'active';
