-- Migration: 001_initial_schema
-- UP
CREATE TABLE schema_version (
    version INT PRIMARY KEY,
    applied_at DATETIME NOT NULL
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    provider_id VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    avatar VARCHAR(500),
    roles JSON NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    invitation_used VARCHAR(64),
    last_login DATETIME,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_provider_id (provider, provider_id),
    UNIQUE KEY unique_email (email),
    INDEX idx_status (status),
    INDEX idx_invitation_used (invitation_used)
);

CREATE TABLE invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(255),
    roles JSON NOT NULL,
    used_by INT,
    used_at DATETIME,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (used_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_expires_at (expires_at)
);

INSERT INTO schema_version (version, applied_at) VALUES (1, NOW());

-- DOWN
DROP TABLE invitations;
DROP TABLE users;
DROP TABLE schema_version;