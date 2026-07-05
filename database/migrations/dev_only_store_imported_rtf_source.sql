-- Dev-only migration snapshot: store_imported_rtf_source
-- UP
ALTER TABLE content_versions
    ADD COLUMN content_rtf MEDIUMTEXT NULL AFTER source;

INSERT INTO schema_version (version, applied_at) VALUES (8, NOW());

-- DOWN
ALTER TABLE content_versions
    DROP COLUMN content_rtf;

DELETE FROM schema_version WHERE version = 8;