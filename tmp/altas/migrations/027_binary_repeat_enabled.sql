INSERT INTO settings (key_name, value) VALUES ('binary_repeat_enabled', '1')
ON DUPLICATE KEY UPDATE value = VALUES(value);
