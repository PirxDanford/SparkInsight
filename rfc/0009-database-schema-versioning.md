# RFC 0009: Database Schema Versioning and Migrations

Status: Draft

Date: 2026-04-18

## Summary

Establish a strategy for managing database schema versions, including upgrades and potential downgrades, to support iterative development and production updates without data loss.

## Problem

As the platform evolves, the database schema will need changes (new tables, columns, indexes, etc.). Without a versioning strategy, deployments become risky, rollbacks are difficult, and coordination between code and database versions is unclear.

## Proposal

1. **Schema Versioning**
   - Maintain a `schema_version` table with fields: `version` (int), `applied_at` (datetime).
   - Each database change is numbered sequentially (e.g., 001, 002, 003).
   - Check current schema version on application startup.

2. **Migration Files**
   - Store migrations in `database/migrations/` directory.
   - Naming: `001_initial_schema.sql`, `002_add_reviews_table.sql`, etc.
   - Each file contains SQL for both upgrade (`UP`) and downgrade (`DOWN`) sections.

3. **Migration Tool**
   - Use a lightweight PHP migration runner (e.g., custom script or Doctrine Migrations adapted for this project).
   - Commands: `migrate up`, `migrate down`, `migrate status`.

4. **Upgrade Strategy**
   - Always run migrations in order.
   - Abort if schema version mismatch detected between code and database.
   - Log all migrations for audit trail.

5. **Downgrade Support**
   - Migrations should support rolling back to previous versions if safe (e.g., revert schema changes).
   - Document which migrations are reversible (some, like data deletions, may not be).
   - Not recommended for production but useful for development/testing.

For local testing:
- Fresh database from migrations during setup.
- Test both upgrade and downgrade paths.
- Verify data integrity after each migration.

## Motivation

Ensures predictable, safe database changes; enables easy rollback if needed; keeps code and schema in sync.

## Open Questions

- Should we use an existing PHP migration library or build a minimal custom solution?
- How to handle schema changes in multi-environment setups (dev, staging, prod)?
- Which migrations should be reversible vs. one-way?

## Links

- Doctrine Migrations: https://www.doctrine-project.org/projects/doctrine-migrations/en/latest/
- Phinx: https://phinx.org/
- RFC 0011: Quality-First Development Framework (migration implementation requires comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (test migration up/down paths)
- ADR 0007: Apply SOLID Principles (clean migration design, separation of concerns)