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

CREATE TABLE content_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    book_title VARCHAR(255) NULL,
    version_label VARCHAR(100) NOT NULL,
    source VARCHAR(255),
    content_text MEDIUMTEXT NULL,
    content_rtf MEDIUMTEXT NULL,
    author_id INT,
    status ENUM('ready', 'placeholder', 'archived') NOT NULL DEFAULT 'ready',
    metadata JSON,
    imported_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_content_version_label (title, version_label),
    INDEX idx_author_id (author_id),
    INDEX idx_content_version_status (status),
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_version_id INT NOT NULL,
    reviewer_id INT,
    title VARCHAR(255) NOT NULL,
    status ENUM('open', 'resolved', 'needs_author_review') NOT NULL DEFAULT 'open',
    details TEXT,
    selected_excerpt TEXT NULL,
    anchor_start_offset INT NULL,
    anchor_end_offset INT NULL,
    anchor_container_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    resolved_at DATETIME,
    FOREIGN KEY (content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_content_version_id (content_version_id),
    INDEX idx_reviewer_id (reviewer_id),
    INDEX idx_review_status (status)
);

CREATE TABLE oauth_identities (
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

CREATE TABLE review_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_version_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    priority ENUM('lowest', 'low', 'normal', 'high', 'highest') NOT NULL DEFAULT 'normal',
    due_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_review_assignment (content_version_id, reviewer_id),
    INDEX idx_review_assignment_reviewer (reviewer_id),
    INDEX idx_review_assignment_due_at (due_at)
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
DROP TABLE review_assignments;
DROP TABLE oauth_identities;
DROP TABLE reviews;
DROP TABLE content_versions;
DROP TABLE invitations;
DROP TABLE users;
DROP TABLE schema_version;