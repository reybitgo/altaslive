-- Migration 011: Add indirect referral enable/disable toggle
-- Default enabled (1) for backward compatibility

INSERT INTO settings (`key_name`, `value`) VALUES ('indirect_referral_enabled', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
