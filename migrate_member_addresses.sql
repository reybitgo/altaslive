-- ============================================================
--  MIGRATION: Member delivery address book
--  IDEMPOTENT — safe to run repeatedly on an existing database.
--  Adds the member_addresses table backing the profile address
--  book and the checkout saved-address prefill.
--  Run: mysql -u USER -p DATABASE < migrate_member_addresses.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS member_addresses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id      INT UNSIGNED NOT NULL,
  label          VARCHAR(40)  NULL,
  recipient_name VARCHAR(120) NOT NULL,
  phone          VARCHAR(40)  NULL,
  address_line   VARCHAR(255) NOT NULL,
  city           VARCHAR(80)  NULL,
  province       VARCHAR(80)  NULL,
  postal         VARCHAR(20)  NULL,
  country        VARCHAR(2)   NOT NULL DEFAULT 'PH',
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_member_addr (member_id, is_default),
  FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
