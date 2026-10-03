-- Royalty Bonus — Pool/Share Model (Phase 1)
-- Creates the royalty_pool table + adds pool-model settings

CREATE TABLE IF NOT EXISTS royalty_pool (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_date DATE NOT NULL COMMENT 'First of month (YYYY-MM-01)',
  total_sales DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Total repeat purchase sales for the month',
  pool_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Computed pool value (total_sales * pool_rate / 100)',
  pool_rate   DECIMAL(5,2) NOT NULL COMMENT 'Pool rate in percent (e.g. 10.00 = 10%)',
  status      ENUM('open','closed','distributed') NOT NULL DEFAULT 'open',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY idx_period (period_date)
) ENGINE=InnoDB;

-- Insert open row for current month
INSERT INTO royalty_pool (period_date, total_sales, pool_amount, pool_rate, status)
VALUES (DATE_FORMAT(NOW(), '%Y-%m-01'), 0, 0, 10.00, 'open')
ON DUPLICATE KEY UPDATE id = id;

-- Add pool-model settings (ignore existing from migration 031)
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('royalty_pool_rate',            '10.00'),
  ('royalty_min_pool',             '500.00'),
  ('royalty_supervisor_rate',      '25'),
  ('royalty_manager_rate',         '25'),
  ('royalty_director_rate',        '25'),
  ('royalty_chairman_rate',        '25'),
  ('royalty_spv_directs',          '10'),
  ('royalty_spv_qa_legs',          '5'),
  ('royalty_mgr_sup_legs',         '3'),
  ('royalty_dir_mgr_legs',         '3'),
  ('royalty_chm_dir_legs',         '3');
