# Production Preparation

This directory is for reproducible deployment preparation when target servers differ (shared hosting, FTP-only, different PHP extension sets).

## Why this exists

- Keep one canonical dependency definition in the project root (`composer.json` + `composer.lock`).
- Avoid long-lived per-environment lockfiles that drift from each other.
- Store target platform profiles and generate per-release snapshots before upload.

## Files

- `profiles/shared-hosting.example.json` - Example target profile.
- `prepare_release.php` - Validates your local build against a profile and writes release metadata.
- `output/` - Generated reports and lock snapshots (gitignored).

## Usage

Run from the project root:

```bash
php deployment/production-prep/prepare_release.php --profile=deployment/production-prep/profiles/shared-hosting.example.json
```

If all checks pass, the script writes:

- `deployment/production-prep/output/<profile>/prep-report.json`
- `deployment/production-prep/output/<profile>/composer.lock.snapshot`

## Workflow

1. Update profile values for your target host.
2. Run prep script.
3. If checks fail, adjust local build environment and re-run.
4. Upload project via FTP with root `composer.lock` and prepared `vendor/`.
5. Optionally keep generated snapshot/report as deployment audit evidence.
