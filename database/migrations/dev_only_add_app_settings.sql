-- Dev-only migration snapshot: add_app_settings
-- UP
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    value_type VARCHAR(16) NOT NULL DEFAULT 'string',
    updated_at DATETIME NOT NULL
);

INSERT INTO app_settings (setting_key, setting_value, value_type, updated_at)
VALUES ('invitation_default_hours', '168', 'int', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW();

INSERT INTO app_settings (setting_key, setting_value, value_type, updated_at)
VALUES ('invitation_default_roles', '["reviewer"]', 'json', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW();

INSERT INTO app_settings (setting_key, setting_value, value_type, updated_at)
VALUES ('import_cron_schedule', '0 2 * * *', 'string', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW();

INSERT INTO app_settings (setting_key, setting_value, value_type, updated_at)
VALUES ('invitation_cleanup_cron_schedule', '30 2 * * *', 'string', NOW())
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), updated_at = NOW();

INSERT INTO schema_version (version, applied_at) VALUES (14, NOW());

-- DOWN
DROP TABLE IF EXISTS app_settings;
DELETE FROM schema_version WHERE version = 14;
