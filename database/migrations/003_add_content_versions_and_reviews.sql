-- Migration: 003_add_content_versions_and_reviews
-- UP
CREATE TABLE IF NOT EXISTS content_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    version_label VARCHAR(100) NOT NULL,
    source VARCHAR(255),
    author_id INT,
    status ENUM('ready', 'archived') NOT NULL DEFAULT 'ready',
    metadata JSON,
    imported_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY unique_content_version_label (title, version_label),
    INDEX idx_author_id (author_id),
    INDEX idx_status (status),
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_version_id INT NOT NULL,
    reviewer_id INT,
    title VARCHAR(255) NOT NULL,
    status ENUM('open', 'resolved', 'needs_author_review') NOT NULL DEFAULT 'open',
    details TEXT,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    resolved_at DATETIME,
    FOREIGN KEY (content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_content_version_id (content_version_id),
    INDEX idx_reviewer_id (reviewer_id),
    INDEX idx_status (status)
);

INSERT INTO schema_version (version, applied_at) VALUES (3, NOW());

-- DOWN
DROP TABLE IF EXISTS reviews;
DROP TABLE IF EXISTS content_versions;
