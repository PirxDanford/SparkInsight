# RFC 0009: Database Schema Versioning and Migrations

Status: Implemented

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
   - Initial development naming: keep `001_initial_schema.sql` as the only numbered baseline; keep incremental chapter-era SQL as explicitly dev-only snapshots (for example, `dev_only_add_reviews_table.sql`).
   - Post-`v1.0.0` release naming: use sequential numbered files for released upgrades (for example, `002_upgrade_v1_0_to_v1_1.sql`).
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

- Doctrine Migrations: https://www.doctrine-project.org/projects/doctrine-migrations/en/current/index.html
- Phinx: https://phinx.org/
- RFC 0011: Quality-First Development Framework (migration implementation requires comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (test migration up/down paths)
- ADR 0007: Apply SOLID Principles (clean migration design, separation of concerns)

## Implementation Notes

- Migration implementation provided by `src/Service/MigrationRunner.php` and the `db:migrate` console command in `src/Command/MigrateDbCommand.php`.
- Migration files live in `database/migrations/` with `001_initial_schema.sql` as the numbered baseline during initial development; prior iterative steps are retained as `dev_only_*.sql` snapshots.
- Unit tests for migration runner and CLI are present in `tests/Unit/Service/MigrationRunnerTest.php` and `tests/Unit/Command/MigrateDbCommandTest.php`.
