-- Dev-only migration snapshot: add_oauth_identities
-- UP
CREATE TABLE IF NOT EXISTS oauth_identities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    provider_email VARCHAR(255),
    linked_at DATETIME NOT NULL,
    last_used_at DATETIME,
    UNIQUE KEY unique_provider_identity (provider, provider_user_id),
    UNIQUE KEY unique_user_provider (user_id, provider),
    INDEX idx_oauth_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO oauth_identities (user_id, provider, provider_user_id, provider_email, linked_at, last_used_at)
SELECT id, provider, provider_id, email, created_at, last_login
FROM users
WHERE provider IS NOT NULL AND provider_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    provider_email = VALUES(provider_email),
    last_used_at = VALUES(last_used_at);

INSERT INTO schema_version (version, applied_at) VALUES (4, NOW());

-- DOWN
DROP TABLE IF EXISTS oauth_identities;