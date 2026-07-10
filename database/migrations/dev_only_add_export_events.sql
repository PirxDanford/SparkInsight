-- Dev-only migration snapshot: add_export_events
-- UP
CREATE TABLE export_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content_version_ids_json JSON NOT NULL,
    export_profile ENUM('secure', 'archive') NOT NULL,
    pdf_standard VARCHAR(40) NOT NULL,
    password_protected TINYINT(1) NOT NULL DEFAULT 0,
    download_ip VARCHAR(64) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_export_events_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT INTO schema_version (version, applied_at) VALUES (11, NOW());

-- DOWN
DROP TABLE export_events;

DELETE FROM schema_version WHERE version = 11;
