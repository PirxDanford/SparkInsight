# RFC 0012: Imports and Migrations Documentation

Status: Implemented

Date: 2026-06-03

## Summary

Provide concrete, repository-hosted documentation for content import formats and the database migration tooling, including recommended CI checks and rollback guidance. This RFC collects the documentation deliverables needed to close knowledge gaps left by implementation work and ensures contributors can operate imports and migrations safely.

## Proposal

- Create a documentation chapter (or pages) covering supported content import formats, validation rules, example Scrivener backup project structures (`.scrivx` + `Files/Data`), dry-run guidance, metadata schema, and performance/size notes. Link to `src/Service/ContentImportService.php` and the `content:import-scrivener` command.
- Create a documentation chapter covering the migration tooling: how to run `db:migrate`, how schema versions are tracked (`schema_version`), how to inspect pending migrations, how to rollback safely, and which migrations are considered irreversible. Link to `src/Service/MigrationRunner.php`, `src/Command/MigrateDbCommand.php`, and `database/migrations/` examples.
- Add recommended CI checks to validate migrations before deployment (e.g., `db:migrate --status` baseline check in a pre-deploy step, or an explicit migration test job), and document the recommended workflow for staging/production migration rollouts.

## Motivation

Implementation of imports and migration tooling exists, but safe operation requires clear, versioned documentation so contributors and operators can validate imports and run migrations without surprises.

## Deliverables

- `docs/imports.md` — Import formats, validation rules, example files, CLI usage, and troubleshooting.
- `docs/migrations.md` — Migration runner usage, rollback guidance, reversible vs one-way migration notes, CI checklist for migrations.
- Update `CONTRIBUTING.md` (via RFC 0005 or Phase 5 deliverables) to reference these docs and describe required migration checks for PRs and releases.

## Links

- `src/Service/ContentImportService.php`
- `src/Command/ScrivenerImportCommand.php`
- `src/Service/MigrationRunner.php`
- `src/Command/MigrateDbCommand.php`
- `database/migrations/`

## Implementation (v1.0.0)

Completed 2026-07-12.

- Delivered `docs/imports.md` with supported formats, validation flow, command usage, and troubleshooting.
- Delivered `docs/migrations.md` with `db:migrate` usage, status/rollback behavior, migration policy, and pre-deploy workflow.
- Added migration CI checks documentation and aligned it with `.github/workflows/migration-checks.yml`.
- Updated `CONTRIBUTING.md` to reference import/migration docs and required migration checks for PRs affecting migration/import behavior.
