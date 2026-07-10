-- Dev-only migration snapshot: add_review_resolution_events
-- UP
CREATE TABLE review_resolution_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    review_id INT NOT NULL,
    source_content_version_id INT NOT NULL,
    target_content_version_id INT NOT NULL,
    previous_status VARCHAR(32) NULL,
    target_status VARCHAR(32) NOT NULL,
    resolution_decision VARCHAR(32) NOT NULL,
    actor_id INT NULL,
    actor_role VARCHAR(32) NULL,
    recorded_at DATETIME NOT NULL,
    FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (source_content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (target_content_version_id) REFERENCES content_versions(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_resolution_events_review_id (review_id),
    INDEX idx_resolution_events_recorded_at (recorded_at),
    INDEX idx_resolution_events_decision (resolution_decision)
);

INSERT INTO schema_version (version, applied_at) VALUES (16, NOW());

-- DOWN
DROP TABLE review_resolution_events;

DELETE FROM schema_version WHERE version = 16;
