# Database Migration Guide

This guide documents migration execution, status checks, rollback behavior, and file conventions.

## Scope

- Command: `php si.php db:migrate`
- Implementation references:
  - `src/Command/MigrateDbCommand.php`
  - `src/Service/MigrationRunner.php`
  - `database/migrations/`

## Core Commands

Show current schema version and pending migrations:

```bash
php si.php db:migrate --status
```

Run pending migrations:

```bash
php si.php db:migrate
```

Rollback the currently applied migration version:

```bash
php si.php db:migrate --rollback
```

Option rules:

- Use only one of `--status` or `--rollback`
- Without either option, the command runs pending migrations

## Version Tracking Model

Migration state is tracked in `schema_version`.

- Current schema version: `MAX(version)` from `schema_version`
- Pending files: migration files with resolved version `>` current version

## File Naming and Version Resolution

Supported filename schemes:

- Release baseline: `001_initial_schema.sql`
- Development snapshots: `dev_only_description.sql`
- Release-upgrade numbering (`002+`) is reserved for semantic release upgrades after `v1.0.0` (for example `v1.1`, `v1.2`, and later)

Version extraction rules:

- `NNN_*.sql`: version is numeric prefix `NNN`
- `dev_only_*.sql`: version is parsed from SQL in the UP section:
  - `INSERT INTO schema_version (version, applied_at) VALUES (<number>, NOW());`

Files with unresolved versions are ignored by the runner.

## Execution and Ordering

- Runner discovers `*.sql` under `database/migrations/`
- Files are sorted by resolved version ascending
- Same-version ties are sorted by filename
- Only versions newer than current schema are executed

## Migration File Structure

Each migration should include:

- An UP section with forward schema/data changes
- A `-- DOWN` marker and rollback SQL
- Schema version insert/delete statements aligned to the file version

Template:

```sql
-- Migration: dev_only_example_change
-- UP
ALTER TABLE example ADD COLUMN demo_field VARCHAR(255) NULL;
INSERT INTO schema_version (version, applied_at) VALUES (25, NOW());

-- DOWN
ALTER TABLE example DROP COLUMN demo_field;
DELETE FROM schema_version WHERE version = 25;
```

## Rollback Behavior

- `--rollback` targets the current version only
- Runner locates the migration file matching current version
- DOWN SQL is required; rollback fails when missing
- After DOWN execution, version row is removed from `schema_version`

Because rollback is single-step and version-based, do not skip DOWN sections in contributor migrations.

## Contributor Workflow

1. Create migration SQL in `database/migrations/` using the naming policy.
2. Include matching UP and DOWN sections.
3. Ensure the UP section inserts `schema_version` for the intended version.
4. Run status before applying:

   ```bash
   php si.php db:migrate --status
   ```

5. Apply migrations:

   ```bash
   php si.php db:migrate
   ```

6. Re-check status:

   ```bash
   php si.php db:migrate --status
   ```

7. Validate rollback path when relevant:

   ```bash
   php si.php db:migrate --rollback
   ```

## Migration CI Checks

SparkInsight enforces migration quality through the main CI workflow in `.github/workflows/ci.yml`.

CI checks include:

- Migration file policy validation via `php tools/ci/check_migrations.php`
- Migration runner unit coverage via `php vendor/bin/phpunit tests/Unit/Service/MigrationRunnerTest.php`
- CLI status smoke check via `php si.php db:migrate --status` (SQLite in-memory)
- CLI option guard check to ensure `--status --rollback` fails as expected

The migration policy validator currently verifies:

- Every migration file is either `001_initial_schema.sql` or `dev_only_*.sql`
- Baseline migration `001_initial_schema.sql` exists
- `dev_only_*.sql` migrations contain a `-- DOWN` section
- `dev_only_*.sql` migrations include a schema version insert marker in UP SQL
- Migration versions are unique across all migration files

Run the same checks locally before opening a PR:

```bash
php tools/ci/check_migrations.php
php vendor/bin/phpunit tests/Unit/Service/MigrationRunnerTest.php
DB_DRIVER=pdo_sqlite DB_NAME=:memory: php si.php db:migrate --status
```

On Windows PowerShell, set temporary environment variables for the status check:

```powershell
$env:DB_DRIVER = 'pdo_sqlite'
$env:DB_NAME = ':memory:'
php .\si.php db:migrate --status
Remove-Item Env:DB_DRIVER
Remove-Item Env:DB_NAME
```

## Recommended Pre-Deploy Gate

Before applying migrations on staging or production:

1. Run CI migration checks and ensure they are green.
2. Run `php si.php db:migrate --status` against the target environment.
3. Confirm pending migration list matches the expected release scope.
4. Execute `php si.php db:migrate` in a controlled deployment step.
5. Re-run `php si.php db:migrate --status` and confirm no pending migrations remain.

## Release Branch Note

Per roadmap/release policy, development history may include `dev_only_*` files, while the release branch is expected to collapse to a single baseline migration (`001_initial_schema.sql`) before final tag. Numbered migrations beyond the baseline are reserved for release-upgrade history after that cutover.

## Troubleshooting

- `Cannot connect to database`: verify `.env` database settings.
- Migration appears ignored: confirm filename is `NNN_*.sql` or `dev_only_*.sql` with a valid `schema_version` insert.
- Rollback reports no migration: current version is `0` or no matching migration file exists.
- Rollback fails with missing section: add proper `-- DOWN` section for that migration.