-- ────────────────────────────────────────────────────────────────
-- Migración: Superadmin + S-Login (impersonation) support
-- IDEMPOTENT — safe to run repeatedly on an existing database.
-- Adds the 'superadmin' role, seeds the 'sadmin' account if missing,
-- and creates the impersonation audit log table if not present.
-- ────────────────────────────────────────────────────────────────

ALTER TABLE users
  MODIFY role ENUM('member','admin','superadmin') NOT NULL DEFAULT 'member';

INSERT INTO users (username, password_hash, role, status, full_name, email)
SELECT 'sadmin',
       '$2y$12$Z.Ylb68X5KH9D.J8l9fYNOlXbkaVXd/S7DcOkwbhoMLn6bDFydHyC', -- Sadmin@1234
       'superadmin',
       'active',
       'Super Administrator',
       'sadmin@mlm.local'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'sadmin');

CREATE TABLE IF NOT EXISTS impersonation_log (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  superadmin_id   INT UNSIGNED NOT NULL,
  superadmin_name VARCHAR(40)  NOT NULL,
  target_user_id  INT UNSIGNED NOT NULL,
  target_username VARCHAR(40)  NOT NULL,
  nonce_hash      VARCHAR(255) NOT NULL,
  ip              VARCHAR(45)  NOT NULL,
  user_agent      VARCHAR(255) NOT NULL,
  status          ENUM('active','expired','logged_out') NOT NULL DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at      DATETIME NOT NULL,
  FOREIGN KEY (superadmin_id) REFERENCES users(id),
  FOREIGN KEY (target_user_id) REFERENCES users(id),
  INDEX idx_imp_super (superadmin_id, created_at),
  INDEX idx_imp_target (target_user_id, created_at),
  INDEX idx_imp_status (status)
) ENGINE=InnoDB;