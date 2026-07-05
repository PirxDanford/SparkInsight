-- Dev-only migration snapshot: store_imported_content_text
-- UP
ALTER TABLE content_versions
    ADD COLUMN content_text MEDIUMTEXT NULL AFTER source;

INSERT INTO schema_version (version, applied_at) VALUES (7, NOW());

-- DOWN
ALTER TABLE content_versions
    DROP COLUMN content_text;

DELETE FROM schema_version WHERE version = 7;