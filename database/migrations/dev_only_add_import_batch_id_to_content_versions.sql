-- Dev-only migration snapshot: add_import_batch_id_to_content_versions
-- UP
ALTER TABLE content_versions
    ADD COLUMN import_batch_id VARCHAR(64) NULL AFTER metadata;

CREATE INDEX idx_content_versions_import_batch_id ON content_versions (import_batch_id);

INSERT INTO schema_version (version, applied_at) VALUES (12, NOW());

-- DOWN
DROP INDEX idx_content_versions_import_batch_id ON content_versions;

ALTER TABLE content_versions
    DROP COLUMN import_batch_id;

DELETE FROM schema_version WHERE version = 12;
