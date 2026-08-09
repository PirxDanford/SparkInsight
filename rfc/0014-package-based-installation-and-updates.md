# RFC 0014: Package-Based Installation and Updates

Status: Implemented

Date: 2026-08-08

Implemented: 2026-08-09

## Summary

Replace file-by-file FTP deployment with signed release packages, a standalone first-install bootstrap, immutable staged releases, and browser-driven updates. Support both full and exact-base patch ZIPs in the first complete delivery.

FTP remains necessary only to place the initial installer. The installer accepts the first full release through a browser upload. After installation, authenticated administrators upload full or patch packages through the SparkInsight Admin interface.

## Decision Context

This RFC implements ADR 0009. It complements, rather than replaces, Composer-based installation and deployment. Composer remains the canonical dependency-management path where server tooling is available.

Version 1 stays PHP-first. A future version 2 may split package recognition, package preparation, and release artifact generation into standalone tooling, including possible native binaries for different target systems.

The FTP-only path targets shared hosting with:

- no SSH or shell access;
- no Git checkout on the host;
- no `rsync` or equivalent synchronization tool;
- no requirement for Composer on the host;
- FTP access for placing the initial installer;
- PHP filesystem access under the same account as uploaded files.

## Problem

Uploading a prepared Composer tree through FTP transfers thousands of small files. This is slow, makes interruption recovery difficult, requires manual tracking of changed and deleted files, and can expose a mixed-version application while an upload is in progress.

The existing checksum manifest detects drift after upload but does not remove the operational burden or prevent partial activation. A supported FTP-only path needs artifact deployment rather than file synchronization.

## Goals

1. Reduce routine installation and update transfer to one ZIP artifact.
2. Keep the active release unchanged while another release is uploaded, materialized, and verified.
3. Activate a verified release by atomically replacing one small pointer file.
4. Support reproducible full packages and exact-base patch packages.
5. Verify package authenticity with Ed25519 and file integrity with SHA-256.
6. Resume interrupted work within bounded shared-host request limits.
7. Preserve `.env`, deployment state, and other shared data across releases.
8. Provide an independent recovery path when the selected application release cannot boot.
9. Keep the previous release until an administrator explicitly finalizes the update.
10. Share package, validation, migration, and activation behavior between CLI tooling and web adapters.

## Non-Goals

- Replacing Composer as the dependency manager or changing `composer.lock` ownership.
- Providing zero-downtime database migrations.
- Supporting arbitrary third-party packages or unsigned production packages.
- Synchronizing manually modified production source files back into a release.
- Automatically reversing production database migrations during code rollback.
- Retaining an indefinite archive of old releases.
- Making FTP file-by-file synchronization reliable.

## Operator Workflows

### First Installation

1. Build and sign a full SparkInsight release ZIP locally.
2. Rename the standalone installer to an unguessable installer filename.
3. Upload only the installer through FTP to the active webroot.
4. Open the installer over HTTPS.
5. Upload the first full release ZIP through the installer.
6. Let the installer verify, incrementally extract, and activate the release.
7. Continue into the existing environment, migration, and first-admin setup flow.
8. On successful installation, write the permanent install lock and delete the installer.

Reinstallation requires deliberate FTP access to restore an installer and remove or explicitly override the install lock. The installer must never offer an unauthenticated reinstall operation on an initialized system.

### Routine Full Update

1. Build and sign a full release ZIP locally.
2. Sign in as an administrator and open the update interface.
3. Upload the ZIP through the browser.
4. Allow the resumable update process to inspect, verify, materialize, and preflight the release.
5. Activate the release and run automated health checks.
6. Exercise critical workflows on the new release.
7. Press **Finalize update** to delete the previous release and completed operation data.

### Routine Patch Update

1. Build and sign a patch from one exact released manifest to another.
2. Upload the patch through the same Admin interface.
3. Require the installed release ID and manifest hash to match the patch base exactly.
4. Clone the immutable base release into staging on the server.
5. Apply changed and added files and the explicit deletion list to staging.
6. Verify the resulting staging tree against the complete target manifest.
7. Continue through the same preflight, migration, activation, health, and finalization path as a full release.

A patch never edits the active release. If the base does not match, the updater refuses the patch and requests a full package.

### Recovery

If an activated release cannot boot before finalization:

1. Open the independent recovery page.
2. Authenticate with the separately generated recovery key.
3. Inspect the active pointer, previous release, update state, and last failure.
4. Atomically restore the previous release pointer.
5. Leave the failed release and operation evidence available until reviewed.

After finalization there is no automatic code rollback target. Recovery then requires a new signed full package or deliberate FTP intervention.

## Permanent Filesystem Layout

The active webroot uses this layout:

```text
/.htaccess                         Stable deployment-kernel rules
/index.php                         Stable release launcher
/recovery.php                      Stable independent recovery entrypoint
/.env                              Shared secret configuration
/.deploy/
  installed.lock                  Permanent first-install lock
  current.json                    Active and previous release pointers
  maintenance.json                Maintenance state read by the launcher
  recovery.json                   Recovery-key hash and rate-limit state
  trusted-release-key.pub         Ed25519 public verification key
  update.lock                     Exclusive update-operation lock
  releases/
    <release-id>/                 Complete immutable application releases
  staging/
    <operation-id>/               Incomplete release materialization
  uploads/
    <operation-id>.zip            Private uploaded packages
  state/
    <operation-id>.json           Resumable operation state
  logs/
    <operation-id>.jsonl          Append-only update audit events
```

Rules:

- `.deploy/`, `.env`, release internals, and package files must be denied by the stable webserver rules.
- `index.php`, `.htaccess`, and `recovery.php` form the deployment kernel and are not ordinary release payload files.
- Releases are immutable after verification. Repair means materializing another release, not editing an active one.
- Release IDs and operation IDs must use strict allowlisted characters and fixed maximum lengths.
- All deployment paths are resolved relative to the deployment root; package-provided absolute paths are forbidden.

## Active Pointer

`current.json` is a small deployment-kernel-owned document:

```json
{
  "format": 1,
  "current": "release-current",
  "previous": "release-previous",
  "activated_at": "2026-08-08T18:30:00+00:00",
  "operation_id": "018f-update-id"
}
```

The launcher validates the schema and release ID, confirms the target entrypoint exists, then requires:

```text
.deploy/releases/<current>/public/index.php
```

Pointer writes use a temporary file in the same directory, `fflush`/`fsync` where available, and `rename()` over the destination. This operation is part of the required host capability contract.

The existing CSS and logo are served through application routes, so switching the launcher pointer switches application code, templates, dependencies, and routed assets together.

## Package Format Version 1

Both package types are ZIP archives containing:

```text
/manifest.json
/manifest.sig
/payload/
  <release-relative files>
```

`manifest.sig` is a detached Ed25519 signature over the exact bytes of `manifest.json`. Verification must occur before trusting any manifest path or extracting payload files.

### Common Manifest Fields

```json
{
  "format": "sparkinsight-release-v1",
  "application": "sparkinsight/sparkinsight",
  "package_id": "unique-package-id",
  "package_type": "full",
  "release_id": "release-target",
  "created_at": "2026-08-08T16:00:00+00:00",
  "minimum_php": "8.1.0",
  "required_extensions": ["json", "openssl", "pdo", "session", "tokenizer", "zip"],
  "composer_lock_sha256": "sha256-hex",
  "expanded_size": 123456789,
  "file_count": 6241,
  "files": [
    {
      "path": "src/Controller/HomeController.php",
      "size": 1383,
      "sha256": "sha256-hex"
    }
  ],
  "payload_files": [
    "src/Controller/HomeController.php"
  ],
  "delete": []
}
```

The complete `files` list describes the final target release for both full and patch packages. `payload_files` describes only ZIP entries delivered by this package.

### Full Package Rules

- `package_type` is `full`.
- Every target file appears in `payload_files` and under `payload/`.
- `delete` is empty.
- The final staged tree must contain exactly the allowed target files after verification.

### Patch Package Rules

- `package_type` is `patch`.
- `base_release_id` and `base_manifest_sha256` are required.
- `payload_files` contains added and changed files only.
- `delete` contains target-relative paths removed by the target release.
- The complete target `files` manifest is mandatory.
- The updater refuses any base release or base manifest mismatch.
- Patch application is idempotent within one operation and occurs only in staging.

### Forbidden Package Content

The parser and extractor must reject:

- absolute, drive-qualified, empty, dot, or parent-traversal paths;
- backslash-based path ambiguity;
- null bytes and control characters;
- symlinks, hard links, devices, and other non-regular entries;
- duplicate paths and case-insensitive path collisions;
- `.env`, `.deploy`, installer, recovery, or deployment-kernel replacements;
- files absent from `payload_files`;
- payload files absent from the ZIP;
- uncompressed sizes, compression ratios, file counts, or path lengths above configured limits;
- packages for another application or unsupported format;
- invalid signatures, hashes, sizes, release IDs, or dependency lock hashes;
- rollback to an older release except through the explicit recovery operation.

Extraction must set a restrictive `umask` and must not trust archived permissions.

## Signing-Key Model

- Generate the Ed25519 signing key outside production.
- Keep the private key outside the repository, release ZIPs, and production host.
- Embed or install only the public key with the deployment kernel.
- Production web flows always reject unsigned packages.
- Local unsigned development packages may be supported only through an explicit non-production CLI flag that cannot be enabled through package content.
- Key rotation requires a separately designed, old-key-authorized transition and is outside format version 1.

## Browser Upload Rules

- Package intake after bootstrap is browser-only in the first implementation.
- Require an authenticated, active administrator and CSRF protection.
- Use `move_uploaded_file()` into `.deploy/uploads/` under a generated operation ID.
- Never use the client filename as a server path.
- Enforce a configured package limit below the effective PHP upload and POST limits.
- Check declared expanded size and free disk before materialization.
- Store uploads and staging content outside direct web access.
- Record upload actor, timestamp, size, package hash, and result without logging secrets.

The initial installer uses the same package parser and verifier but is protected by an unguessable URL, HTTPS, rate limiting, and absence of `installed.lock`.

## Resumable Update State Machine

No filesystem-heavy operation may assume it can complete within one shared-host request. Work is divided into bounded, idempotent steps persisted in `.deploy/state/<operation-id>.json`.

States:

1. `uploaded` - Browser upload moved into private storage and package SHA-256 recorded.
2. `inspected` - ZIP structure and bounded metadata inspected without extraction.
3. `authenticated` - Manifest signature, application, format, release ordering, and patch base verified.
4. `preparing` - Disk, runtime, lock, and destination checks completed.
5. `materializing` - Full extraction or patch-base copying proceeds in bounded batches.
6. `overlaying` - Patch additions, changes, and deletions are applied in staging.
7. `verifying` - Staged files are hashed and compared with the complete target manifest in batches.
8. `preflighted` - Autoload, Composer platform requirements, entrypoint, migration discovery, and required paths pass.
9. `maintenance` - Stable launcher serves a maintenance response to normal requests.
10. `migrating` - Pending SQL migrations from the staged release are applied.
11. `activating` - `current.json` is atomically replaced while retaining the previous pointer.
12. `health_checking` - New release bootstrap and application health checks run.
13. `awaiting_finalization` - New release is active and previous release remains recoverable.
14. `finalizing` - Administrator confirmation removes the previous release and transient operation files.
15. `completed` - Audit result is retained; update lock and maintenance state are clear.
16. `failed` - Failure details and the last safe resumable state are recorded.
17. `recovered` - Recovery restored the previous pointer after activation.

Each state transition must:

- acquire the exclusive update lock;
- validate the persisted state schema and expected prior state;
- perform a bounded amount of idempotent work;
- persist progress atomically;
- append an audit event;
- release the lock before returning;
- return enough information for the browser to request the next step.

The browser may advance steps through POST requests or controlled polling. Refreshing or repeating a request must not duplicate migrations, delete the active release, or corrupt progress.

## Locking and Concurrency

- Permit one install/update/finalization/recovery mutation at a time.
- Use `flock()` on `.deploy/update.lock` when supported and persist lock-owner metadata for diagnostics.
- Treat metadata age as diagnostic information, not permission to ignore a live OS lock.
- Reject another administrator's attempt to start a second operation.
- Allow recovery to inspect state without mutation; recovery mutation must acquire the same lock.

## Migration Strategy

The staged release's `database/migrations/` directory is passed to the existing migration service before pointer activation.

Required order:

1. Verify and preflight the complete staged release.
2. Enable maintenance mode.
3. Record current schema version and pending migration list.
4. Run pending migrations from the staged release.
5. Confirm no expected migration remains pending.
6. Atomically activate the staged release.
7. Run health checks.
8. Disable maintenance mode when the new release is healthy.

If migration fails, the pointer remains unchanged and maintenance mode is removed after recording the failure.

Code recovery does not automatically reverse migrations. Release migrations must follow expand-and-contract compatibility:

- additive schema changes precede code dependence on them;
- the previous release must remain functional against the expanded schema until finalization;
- destructive changes are delayed to a later release after the compatibility window;
- irreversible data changes require explicit release notes and backup guidance;
- a release that violates backward compatibility must declare recovery limitations and require explicit operator confirmation.

## Preflight and Health Checks

Preflight occurs before maintenance and activation:

- package and complete staged manifest verification;
- required PHP version and extensions;
- `vendor/autoload.php` loadability in an isolated check where possible;
- Composer platform-check compatibility;
- required application entrypoint and directories;
- valid `composer.lock` JSON and expected lock hash;
- readable migration files with unique versions;
- writable shared deployment paths;
- sufficient disk for activation and retained previous release.

Post-activation health checks include:

- launcher resolves the selected release;
- application bootstrap completes without rendering debug details;
- database connection succeeds;
- migration status reports no expected pending migration;
- a protected health endpoint returns the active release ID;
- required routed assets respond successfully.

Automated checks move the operation to `awaiting_finalization`; they do not delete the previous release.

## Finalization

Finalization is an explicit administrator POST action with CSRF protection and confirmation.

It:

1. verifies the operation is `awaiting_finalization`;
2. confirms the active pointer still targets the operation's release;
3. removes the previous release recursively using bounded resumable batches;
4. clears `previous` from `current.json` atomically;
5. removes the uploaded ZIP, staging remnants, and temporary state;
6. retains a compact audit result;
7. marks the operation `completed`.

Until finalization, another update is blocked. This keeps recovery semantics unambiguous.

## Independent Recovery Page

`recovery.php` is part of the stable deployment kernel and must not require the active release's autoloader, templates, session implementation, or database connection.

Requirements:

- HTTPS only outside local development;
- separate high-entropy recovery key generated during installation and shown once;
- only a password hash stored in `.deploy/recovery.json`;
- constant-time verification, rate limiting, and generic authentication failures;
- no display of `.env`, credentials, absolute paths, stack traces, or package content;
- recovery actions limited to inspection, safe resume/abort, maintenance clearing, and previous-pointer restoration;
- every mutation logged and protected by the deployment lock;
- no arbitrary file editor, command execution, or unsigned package installation.

If the recovery key is lost, deliberate FTP access is the break-glass path for replacing recovery credentials or the pointer.

## Deployment-Kernel Updates

The stable launcher, webserver rules, and recovery page are intentionally outside ordinary release payloads. Format version 1 does not permit packages to overwrite them.

Kernel changes require a separately specified two-phase mechanism that keeps the previous kernel recoverable. Until then, kernel updates are exceptional, versioned installer maintenance operations rather than routine application updates.

## Error Handling and Audit

- Production responses show stable operator messages and operation IDs, never raw stack traces.
- Detailed errors are written under `.deploy/logs/`, which is denied from web access.
- Audit events include actor ID where available, operation ID, package ID/hash, release IDs, state transition, timestamps, and normalized result codes.
- Secrets, recovery keys, database credentials, OAuth credentials, and raw session data are never logged.
- Failed staging directories remain available for diagnosis until explicit cleanup unless they contain a rejected untrusted package, in which case payload extraction must not have occurred.

## Proposed Code Boundaries

Domain behavior should be framework-independent and reusable from CLI, installer, Admin, and recovery adapters.

Suggested responsibilities:

- `ReleaseManifest` - strict manifest parsing and invariants.
- `ReleaseSignatureVerifier` - detached Ed25519 verification.
- `ReleasePathPolicy` - normalized allowlisted package paths.
- `ReleasePackageInspector` - bounded ZIP metadata inspection.
- `ReleaseMaterializer` - batched full extraction and patch reconstruction.
- `ReleaseVerifier` - batched target-tree hashing and exactness checks.
- `DeploymentStateStore` - atomic state and pointer persistence.
- `DeploymentLock` - exclusive mutation lock.
- `DeploymentPreflight` - platform, disk, autoload, and migration checks.
- `DeploymentActivator` - maintenance and pointer transitions.
- `DeploymentFinalizer` - bounded cleanup after confirmation.
- `ReleasePackageBuilder` - local full and patch package generation.

Web controllers and CLI commands remain thin adapters over these services in accordance with ADR 0007 and ADR 0008.

## Test Strategy

### Unit Tests

- strict manifest schemas for full and patch packages;
- signature success, wrong key, modified manifest, malformed signature;
- path traversal, absolute path, collision, symlink, and reserved-path rejection;
- full and patch invariants, including exact base enforcement;
- atomic pointer and state transitions;
- legal and illegal state-machine transitions;
- batch cursor persistence and idempotent retries;
- finalization and recovery authorization rules;
- upload size, expanded size, ratio, file-count, and disk guards.

### Integration Tests

- build, sign, inspect, extract, and verify a full package;
- build and apply a patch, then compare its target tree byte-for-byte with the full target package;
- interrupt and resume extraction, copy, verification, and deletion batches;
- inject failures before migration, during migration, before pointer rename, and after activation;
- prove the old pointer remains active before activation failures;
- prove recovery restores the previous pointer after activation failure;
- exercise migrations from a staged release directory;
- verify `.env` and deployment state survive updates;
- verify release directories are inaccessible over HTTP.

### Web and Security Tests

- installer blocked after `installed.lock` exists;
- installer filename and HTTPS requirements;
- Admin authentication, role, CSRF, and concurrent-operation rejection;
- recovery-key rate limiting and generic failures;
- no stack traces or absolute paths in production responses;
- malicious ZIP and decompression-bomb fixtures;
- upload interruption and duplicate-step requests;
- health checks for launcher, database, release ID, and routed assets.

### Acceptance Test

On an FTP-only production-equivalent host:

1. Upload only the installer through FTP.
2. Install from one signed full ZIP through the browser.
3. Complete environment, migration, and first-admin setup.
4. Upload and activate a signed patch through Admin.
5. Recover to the previous release before finalization.
6. Reactivate the target release and finalize it.
7. Upload and activate a signed full release.
8. Confirm no file-by-file FTP update was required.

## Implementation Sequence

### Phase 1: Package Core

- Define strict manifest DTOs and validation.
- Implement path policy and ZIP inspection.
- Implement Ed25519 signing and verification.
- Implement local full-package builder.
- Implement exact-base patch builder.
- Prove full and patch target equivalence in tests.

### Phase 2: Deployment Core

- Implement deployment layout, atomic state store, pointer, and lock.
- Implement bounded materialization and verification.
- Implement preflight, maintenance, migration, activation, and finalization services.
- Add interruption and failure-injection integration tests.

### Phase 3: Installer and Stable Kernel

- Implement standalone installer using the package core.
- Implement stable launcher and webserver deny rules.
- Implement install lock and installer self-deletion.
- Implement independent recovery page and recovery-key setup.
- Add simulated empty-webroot installation tests.

### Phase 4: Admin Update Experience

- Add authenticated package upload and progress UI.
- Add resumable step execution and clear failure states.
- Add activation, health, recovery status, and explicit finalization UI.
- Add audit display without secret or path disclosure.

### Phase 5: Production Validation

- Build signed full and patch artifacts.
- Validate on the confirmed FTP-only host.
- Replace the temporary file-sync runbook with permanent operator documentation.
- Keep the prior deployment path available only until package installation and recovery acceptance tests pass.

## Acceptance Criteria

This RFC is implemented when:

- a clean host can be installed by FTP-uploading one installer and browser-uploading one signed full ZIP;
- routine full and patch updates require no FTP transfer;
- active code is never modified during staging;
- invalid, unsigned, mismatched-base, traversal, symlink, oversized, and corrupted packages are rejected before activation;
- interrupted operations resume safely across requests;
- migrations run from the staged release before pointer activation;
- failed pre-activation updates leave the current release active;
- the independent recovery page restores the previous pointer after a failed activation;
- the previous release is deleted only through explicit finalization;
- `.env` and shared state survive installation and updates;
- production failures do not expose stack traces or absolute server paths;
- Composer installation remains supported and unchanged.

## Documentation Deliverables

- Package builder and release-signing guide.
- First-install guide for FTP-only hosting.
- Admin full and patch update guide.
- Recovery and finalization guide.
- Signing-key custody and rotation warning.
- Migration compatibility checklist for releases.
- Updated production security and error-display guidance.

## Links

- ADR 0009: Support Package-Based Deployment on Constrained Hosting
- ADR 0008: CLI-First Operations with Admin UI Fallback
- ADR 0007: Apply SOLID Principles
- ADR 0006: Adopt Test-Driven Development
- RFC 0009: Database Schema Versioning and Migrations
- RFC 0010: GitHub Actions Quality Gates
- RFC 0011: Quality-First Development Framework
- `tools/deploy/deploy.php`
- `src/Service/MigrationRunner.php`
