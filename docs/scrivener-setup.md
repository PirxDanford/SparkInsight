# Scrivener Setup and Author Workflow

This guide explains how authors should prepare Scrivener projects and backups so imports into SparkInsight are predictable, review-ready, and easy to troubleshoot.

Use this guide together with:

- `docs/imports.md` for CLI import execution and batch maintenance
- `docs/setup.md` for local/server environment setup

## Quick Start Checklist

1. Keep one Scrivener project per book/review stream.
2. Organize binder structure consistently before each backup.
3. Generate a fresh backup snapshot from Scrivener (do not overwrite older snapshots).
4. Confirm backup includes the `.scrivx` file and `Files/Data` content.
5. Place backup under the import source directory (default: `scrivener/`).
6. Run dry-run import validation:

   ```bash
   php si.php content:import-scrivener --directory="scrivener" --dry-run
   ```

7. Only run the real import after dry-run reports no validation failures.

## 1. Project Setup for Review Workflows

### Binder Organization

For stable review navigation and anchor quality:

- Keep chapter and scene hierarchy consistent across versions.
- Avoid large structural moves right before creating a review backup.
- Use clear item titles; these are surfaced in import metadata and UI lists.
- Keep non-manuscript/support material outside the manuscript binder root when possible.

Importer root selection currently prefers:

1. `BinderItem` titled `The Book`
2. First `BinderItem` with `Type="DraftFolder"`
3. First `BinderItem` under `/ScrivenerProject/Binder`

If your manuscript root differs, align naming/structure so one of these rules matches the intended manuscript root.

### Naming Conventions

- Project folder: keep a stable book-level name.
- Binder item titles: use readable, durable titles rather than temporary placeholders.
- Backup snapshot names: include book shortname plus timestamp.

Recommended backup naming pattern:

```text
<book-shortname>_backup_YYYY-MM-DD_HHMM
```

Example:

```text
ecm_backup_2026-07-12_2130
```

## 2. Backup Workflow in Scrivener

### Manual Backup Workflow (recommended for release-critical imports)

1. In Scrivener, open your project and verify it loads cleanly.
2. Trigger `File -> Back Up -> Back Up To...`.
3. Save to a dedicated export/drop location for SparkInsight imports.
4. Keep each snapshot immutable after it is used for import.

### Automated Backup Workflow

If your author process uses automatic backups:

- Ensure backup location is known and accessible for import staging.
- Retain enough history to recover from a bad import source snapshot.
- Periodically verify automatic backups are complete (not partial/corrupt).

### Backup Location Conventions

For repository-local testing and operator handoff, use a predictable staging path:

- Default: `scrivener/`
- Keep one folder per backup snapshot/project.
- Do not mix unrelated experimental backups into the same import run without intent.

## 3. Validate Backup Integrity Before Import

A valid backup for SparkInsight import should contain:

- A project `.scrivx` file
- `Files/Data/<UUID>/content.rtf` content files

Quick validation flow:

1. Confirm expected files are present.
2. Run importer dry-run.
3. Fix structural/content issues.
4. Re-run dry-run until clean.

Common integrity risks:

- Incomplete copy/upload of backup files
- Missing `.scrivx` project metadata
- Missing `Files/Data` subtree
- Unexpected binder root layout

## 4. Version Management for Authors

To keep review history and remapping meaningful:

- Import from intentional milestone snapshots, not from every minor save.
- Preserve chronological snapshot order.
- Keep prior snapshots available until new import is validated.
- Use stable content structure where practical to improve anchor remap confidence.

## 5. Integration with SparkInsight Review Flow

How setup choices affect review quality:

- Better binder consistency improves hierarchy and navigation continuity.
- Stable item titles and structure reduce anchor remap noise.
- Clean backups improve import success rate and lower recovery/purge work.

After import:

1. List and record import batches:

   ```bash
   php si.php content:list-imports --limit=20
   ```

2. Spot-check imported items in author/reviewer dashboards.
3. If needed, purge only the affected batch and re-import from corrected source.

## 6. Troubleshooting and Recovery

If import fails or quality is unexpectedly low:

- Re-check backup completeness and `.scrivx` parse health.
- Run dry-run again before attempting write import.
- Adjust snapshot labeling (`--label-prefix`) when duplicate version labels occur.
- Purge the problematic import batch only, then retry from corrected backup.

See `docs/imports.md` for command-level troubleshooting and purge semantics.

## 7. Platform Notes (Windows/macOS)

- UI labels in Scrivener may vary slightly by platform version.
- The workflow outcome is the same: produce a complete backup containing `.scrivx` + `Files/Data`.
- Standardize team conventions (naming, backup frequency, staging path) across platforms.

## 8. Quality-First Alignment

This documentation aligns with:

- RFC 0011 (Quality-First Development Framework): run validation before write operations and verify outcomes after import.
- ADR 0006 (TDD): prefer repeatable validation steps that can be verified during change cycles.
- ADR 0007 (SOLID): keep setup steps explicit and responsibilities separated (author backup prep vs operator import execution).

For importer command details and operational commands, continue with `docs/imports.md`.