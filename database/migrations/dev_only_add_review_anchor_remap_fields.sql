-- Dev-only migration snapshot: add_review_anchor_remap_fields
-- UP
ALTER TABLE reviews
    ADD COLUMN anchor_remap_state VARCHAR(24) NULL AFTER anchor_container_path,
    ADD COLUMN anchor_remap_confidence VARCHAR(16) NULL AFTER anchor_remap_state,
    ADD COLUMN anchor_remap_reason VARCHAR(255) NULL AFTER anchor_remap_confidence,
    ADD COLUMN anchor_remapped_from_review_id INT NULL AFTER anchor_remap_reason,
    ADD COLUMN anchor_remapped_from_content_version_id INT NULL AFTER anchor_remapped_from_review_id,
    ADD CONSTRAINT fk_reviews_anchor_remapped_from_review
        FOREIGN KEY (anchor_remapped_from_review_id) REFERENCES reviews(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_reviews_anchor_remapped_from_content_version
        FOREIGN KEY (anchor_remapped_from_content_version_id) REFERENCES content_versions(id) ON DELETE SET NULL,
    ADD INDEX idx_review_anchor_remap_state (anchor_remap_state),
    ADD INDEX idx_review_anchor_remap_confidence (anchor_remap_confidence);

INSERT INTO schema_version (version, applied_at) VALUES (15, NOW());

-- DOWN
ALTER TABLE reviews
    DROP FOREIGN KEY fk_reviews_anchor_remapped_from_content_version,
    DROP FOREIGN KEY fk_reviews_anchor_remapped_from_review,
    DROP INDEX idx_review_anchor_remap_confidence,
    DROP INDEX idx_review_anchor_remap_state,
    DROP COLUMN anchor_remapped_from_content_version_id,
    DROP COLUMN anchor_remapped_from_review_id,
    DROP COLUMN anchor_remap_reason,
    DROP COLUMN anchor_remap_confidence,
    DROP COLUMN anchor_remap_state;

DELETE FROM schema_version WHERE version = 15;
