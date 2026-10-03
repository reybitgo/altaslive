-- Royalty Bonus — Leadership Ranks
-- Adds rank tracking column + settings keys + extends commissions type ENUM

ALTER TABLE users
  ADD COLUMN rank_royalty ENUM('qa','supervisor','manager','director','chairman') NULL DEFAULT NULL AFTER group_pv;

ALTER TABLE commissions
  MODIFY COLUMN type ENUM('pairing','direct_referral','indirect_referral',
                          'daily_fixed_income','unilevel_product','royalty')
  NOT NULL;

-- Settings defaults
INSERT IGNORE INTO settings (key_name, value) VALUES
  ('royalty_enabled',            '0'),
  ('royalty_qa_directs',         '3'),
  ('royalty_qa_personal_pv',     '200'),
  ('royalty_qa_group_pv',       '1000'),
  ('royalty_supervisor_group_pct', '3'),
  ('royalty_supervisor_repeat_pct', '5'),
  ('royalty_manager_group_pct',    '5'),
  ('royalty_manager_repeat_pct',   '10'),
  ('royalty_director_group_pct',   '10'),
  ('royalty_director_repeat_pct',  '15'),
  ('royalty_chairman_group_pct',   '12'),
  ('royalty_chairman_repeat_pct',  '20');
