-- Migration 013: Add binary pairing enable/disable toggle
-- Default enabled (1) for backward compatibility

INSERT INTO settings (`key_name`, `value`) VALUES ('binary_enabled', '1')
ON DUPLICATE KEY UPDATE `value` = `value`;
