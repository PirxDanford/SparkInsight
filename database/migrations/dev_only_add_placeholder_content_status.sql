-- Dev-only migration snapshot: add_placeholder_content_status
-- UP
ALTER TABLE content_versions
    MODIFY status ENUM('ready', 'placeholder', 'archived') NOT NULL DEFAULT 'ready';

UPDATE content_versions
SET status = 'placeholder', updated_at = NOW()
WHERE status = 'ready'
  AND JSON_EXTRACT(metadata, '$.section_count') = 0;

INSERT INTO schema_version (version, applied_at) VALUES (6, NOW());

-- DOWN
UPDATE content_versions
SET status = 'ready', updated_at = NOW()
WHERE status = 'placeholder';

ALTER TABLE content_versions
    MODIFY status ENUM('ready', 'archived') NOT NULL DEFAULT 'ready';

DELETE FROM schema_version WHERE version = 6;