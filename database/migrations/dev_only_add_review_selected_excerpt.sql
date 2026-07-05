-- Dev-only migration snapshot: add_review_selected_excerpt
-- UP
ALTER TABLE reviews
    ADD COLUMN selected_excerpt TEXT NULL AFTER details;

INSERT INTO schema_version (version, applied_at) VALUES (9, NOW());

-- DOWN
ALTER TABLE reviews
    DROP COLUMN selected_excerpt;

DELETE FROM schema_version WHERE version = 9;