# Content Import Guide

This guide documents the importer workflow for Scrivener backup projects and the related maintenance commands.

## Scope

- Command: `php si.php content:import-scrivener`
- Implementation references:
  - `src/Command/ScrivenerImportCommand.php`
  - `src/Service/ContentImportService.php`

## Supported Input

The importer accepts Scrivener backup project inputs resolved from `--directory`:

- A direct `.scriv` project directory
- A direct `.scrivx` project file
- A parent directory that contains one or more `.scriv` project directories

Inside a project, importer data is read from:

- Project structure: `<project>.scrivx`
- Item content files: `Files/Data/<UUID>/content.rtf`

## Root Selection Rules

When parsing `.scrivx`, the importer resolves the root binder node in this order:

1. `BinderItem` titled `The Book`
2. First `BinderItem` with `Type="DraftFolder"`
3. First `BinderItem` under `/ScrivenerProject/Binder`

If none is found, the command fails with validation errors.

## Command Usage

Basic import:

```bash
php si.php content:import-scrivener --directory="scrivener"
```

Dry-run validation only:

```bash
php si.php content:import-scrivener --directory="scrivener" --dry-run
```

Import with explicit author, book, and version label prefix:

```bash
php si.php content:import-scrivener \
  --directory="scrivener" \
  --author-id=1 \
  --book-title="Enterprise Community Management" \
  --label-prefix="ecm-v2"
```

## Command Options

- `--directory` (required value): Import source path. Default: `scrivener/`
- `--author-id` (required value): Author ID for imported content. Default: `1`
- `--book-title` (optional): Override/import-batch book title
- `--label-prefix` (optional): Prefix for generated `version_label`
- `--dry-run` (flag): Validate and scan without database writes

## End-to-End Scrivener Backup Import Workflow

Use this checklist for repeatable, low-risk imports from a Scrivener backup into SparkInsight.

1. Prepare the backup in Scrivener using the "Back Up To" dialog
2. Stage backup files for import:
  - Place backup projects under a known import directory (default: `scrivener/`).
3. Run dry-run validation before writing data:

```bash
php si.php content:import-scrivener --directory="scrivener" --dry-run
```

4. Review dry-run output:
  - Confirm project discovery count matches expectations.
  - Confirm binder parsing succeeded (no `.scrivx` parse errors).
  - Resolve failures before continuing.
5. Execute the import:

```bash
php si.php content:import-scrivener --directory="scrivener"
```

6. Verify the imported batch:

```bash
php si.php content:list-imports --limit=20
```

  - Record the returned `import_batch_id` values.
  - Spot-check a few imported items in the reviewer/author dashboards.
7. Roll back quickly if needed:

```bash
php si.php content:purge-imports --force --id=<import-id>
```

  - Purge only the affected batch IDs.
  - Re-run dry-run and import after fixing source data.

### Recommended Backup Hygiene

- Use timestamped backup naming so each import can be traced to source files.
- Keep backup snapshots immutable once imported; create a new snapshot for each new version.
- Avoid importing partially copied backups (missing `.scrivx` or incomplete `Files/Data`).
- Prefer running imports from a dedicated operator directory, not directly from active author project folders.

## What Gets Stored

For each binder item, the importer persists a `content_versions` row with:

- `title`: Binder title (`Untitled Item` fallback)
- `book_title`: Binder-root derived title or `--book-title` override
- `version_label`: Deterministic label from binder path/order (plus optional prefix)
- `source`: Project-relative path to `content.rtf` when present
- `content_rtf`: Raw RTF source if available
- `content_text`: Plain-text extraction from RTF
- `status`:
  - `ready` when extracted text has content
  - `placeholder` when item has no text content
- `metadata` JSON:
  - Import format/source hash/section count
  - Scrivener fields such as UUIDs, order path, list path, item kind, and project info

Also during import:

- Active reviewers are auto-assigned in `review_assignments`
- Anchor remap metadata may be generated when a prior version of the same authored item exists

## Validation and Failure Behavior

- The command reports scanned projects/files and imported/failed items.
- Any failed item causes a non-zero command exit.
- Import is item-transactional, not full-batch transactional:
  - Successfully imported items remain persisted even if other items fail.
- Duplicate protection rejects inserts when `(title, version_label)` already exists.

Recommended safe flow:

1. Run `--dry-run` first.
2. Resolve reported failed items.
3. Run without `--dry-run`.

## Import Batch Tracking and Cleanup

After successful imports, each row receives an `import_batch_id`.

List recent import batches:

```bash
php si.php content:list-imports --limit=100
```

Purge one or more specific batches:

```bash
php si.php content:purge-imports --force --id=<import-id>
php si.php content:purge-imports --force --id=<import-id-1> --id=<import-id-2>
```

Important purge behavior:

- `--force` is mandatory
- Purge deletes matching `content_versions` and related `reviews` / `review_assignments`
- If `--id` is omitted, the command deletes all imported content data

## Troubleshooting

- `No Scrivener project backups were found`: verify `--directory` points to a `.scriv`, `.scrivx`, or parent folder containing `.scriv` projects.
- `.scrivx` parse errors: confirm the project XML is valid and complete in the backup.
- `Could not find a .scrivx file in project directory`: verify backup completeness.
- Duplicate content version errors: adjust `--label-prefix` or clean up earlier imports before retry.