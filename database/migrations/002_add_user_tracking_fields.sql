-- Migration: 002_add_user_tracking_fields
-- UP
ALTER TABLE users
ADD COLUMN IF NOT EXISTS status ENUM('active', 'disabled') NOT NULL DEFAULT 'active' AFTER roles,
ADD COLUMN IF NOT EXISTS invitation_used VARCHAR(64) AFTER status,
ADD COLUMN IF NOT EXISTS last_login DATETIME AFTER invitation_used,
ADD INDEX IF NOT EXISTS idx_status (status),
ADD INDEX IF NOT EXISTS idx_invitation_used (invitation_used);

INSERT INTO schema_version (version, applied_at) VALUES (2, NOW());

-- DOWN
ALTER TABLE users
DROP COLUMN status,
DROP COLUMN invitation_used,
DROP COLUMN last_login,
DROP INDEX idx_status,
DROP INDEX idx_invitation_used;