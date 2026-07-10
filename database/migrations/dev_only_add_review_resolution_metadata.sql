-- Dev-only migration snapshot: add_review_resolution_metadata
-- UP
ALTER TABLE reviews
    ADD COLUMN resolution_decision VARCHAR(32) NULL AFTER resolved_at,
    ADD COLUMN resolution_actor_id INT NULL AFTER resolution_decision,
    ADD COLUMN resolution_actor_role VARCHAR(32) NULL AFTER resolution_actor_id,
    ADD COLUMN resolution_recorded_at DATETIME NULL AFTER resolution_actor_role;

ALTER TABLE reviews
    ADD CONSTRAINT fk_reviews_resolution_actor_id FOREIGN KEY (resolution_actor_id) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_review_resolution_decision ON reviews (resolution_decision);

INSERT INTO schema_version (version, applied_at) VALUES (13, NOW());

-- DOWN
ALTER TABLE reviews
    DROP FOREIGN KEY fk_reviews_resolution_actor_id;

DROP INDEX idx_review_resolution_decision ON reviews;

ALTER TABLE reviews
    DROP COLUMN resolution_recorded_at,
    DROP COLUMN resolution_actor_role,
    DROP COLUMN resolution_actor_id,
    DROP COLUMN resolution_decision;

DELETE FROM schema_version WHERE version = 13;