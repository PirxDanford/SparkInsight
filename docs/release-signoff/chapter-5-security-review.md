# Chapter 5 Security Review

Date: 2026-07-12

## Scope

This review closes the Chapter 5 security-review backlog item for `v1.0.0` release preparation.

Reviewed areas:

- Release readiness controls
- Contributor enforcement controls
- Deployment controls for Composer and FTP-style hosting

## Findings

### Release readiness controls

- Main CI now runs for `main` and `release/*` pushes and pull requests.
- Migration checks now run for `main` and `release/*` pushes and pull requests when migration-related files change.
- Unsigned commits remain warning-level outside release stabilization, but they fail CI on `release/*` branches.
- Coverage, PHPUnit, PHPStan, PHP-CS-Fixer, Composer validation, and PHP syntax checks remain part of the required automated gate.

Result: acceptable for `v1.0.0` release preparation, provided branch protection in GitHub requires the relevant CI checks before merge/tagging.

### Contributor enforcement controls

- `CONTRIBUTING.md` requires TDD, SOLID, and Conventional Commits for all contributions.
- Release-branch work is additionally constrained by signed-commit enforcement in CI.
- Migration-affecting changes have an explicit contributor checklist and dedicated CI workflow.

Result: acceptable for a solo-maintainer repository with CI-backed release stabilization.

### Deployment controls

- `docs/Setup.md` documents production OAuth configuration, secret handling, and FTP-hosting precautions.
- `deployment/production-prep/README.md` documents reproducible pre-upload preparation and audit artifact capture.
- `docs/migrations.md` documents pre-deploy status checks, controlled migration execution, and rollback expectations.
- `README.md` documents temporary use and removal of `public/install_diagnose.php` for FTP-only validation.

Result: acceptable for current deployment model, with the following operational requirements retained for release cutover:

- Protect `.env` and OAuth secrets on the target host.
- Remove or disable install diagnostics immediately after validation.
- Keep deployment prep reports and latest green CI evidence with the release signoff notes.
- Confirm GitHub branch protection requires CI before merging or tagging the release branch.

## Conclusion

No new Chapter 5 design changes are required after this review. The remaining Chapter 5 release tasks are execution and evidence-capture tasks for the already-defined controls.