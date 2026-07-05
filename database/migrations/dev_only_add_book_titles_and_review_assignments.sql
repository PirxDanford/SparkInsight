-- Dev-only migration snapshot: add_book_titles_and_review_assignments
-- UP
ALTER TABLE content_versions
    ADD COLUMN book_title VARCHAR(255) NULL AFTER title;

UPDATE content_versions
SET book_title = title
WHERE book_title IS NULL;

CREATE TABLE IF NOT EXISTS review_assignments (
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

INSERT INTO review_assignments (content_version_id, reviewer_id, priority, due_at, created_at, updated_at)
SELECT DISTINCT r.content_version_id, r.reviewer_id, 'normal', NULL, NOW(), NOW()
FROM reviews r
WHERE r.reviewer_id IS NOT NULL
ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at);

INSERT INTO schema_version (version, applied_at) VALUES (5, NOW());

-- DOWN
DROP TABLE IF EXISTS review_assignments;

ALTER TABLE content_versions
    DROP COLUMN book_title;