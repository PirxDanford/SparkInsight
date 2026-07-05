-- Dev-only migration snapshot: add_review_anchor_fields
-- UP
ALTER TABLE reviews
    ADD COLUMN anchor_start_offset INT NULL AFTER selected_excerpt,
    ADD COLUMN anchor_end_offset INT NULL AFTER anchor_start_offset,
    ADD COLUMN anchor_container_path VARCHAR(255) NULL AFTER anchor_end_offset;

INSERT INTO schema_version (version, applied_at) VALUES (10, NOW());

-- DOWN
ALTER TABLE reviews
    DROP COLUMN anchor_container_path,
    DROP COLUMN anchor_end_offset,
    DROP COLUMN anchor_start_offset;

DELETE FROM schema_version WHERE version = 10;
