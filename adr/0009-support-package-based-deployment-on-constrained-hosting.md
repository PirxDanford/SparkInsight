# ADR 0009: Support Package-Based Deployment on Constrained Hosting

Status: Accepted

Date: 2026-08-08

## Context

SparkInsight's primary maintainer currently operates the production instance on inexpensive, personally funded shared hosting. The maintainer intentionally does not pay for a higher hosting tier solely to obtain SSH, Git, `rsync`, server-side Composer, or managed deployment facilities.

This is a budget choice, not a claim that FTP-only hosting is technically superior. A conventional Composer-based deployment remains the preferred path when suitable server tooling exists.

However, small personal, community, nonprofit, and self-funded projects often face the same constraints. Supporting a safe package-based path therefore benefits users beyond the current maintainer and prevents low-cost hosting from requiring unsafe file-by-file updates.

The relevant constrained environment provides:

- FTP and HTTPS access;
- PHP and MariaDB;
- no SSH or interactive shell;
- no Git or `rsync` on the host;
- no requirement for Composer on the host;
- direct PHP write access to files owned by the FTP account;
- ZIP extraction, Sodium signatures, atomic file replacement, and directory rename.

The existing manual FTP mirror process transfers thousands of Composer-managed files, requires explicit deletion tracking, and risks mixed-version application state during upload. Its complexity is a consequence of treating FTP as a synchronization protocol rather than transporting a release artifact.

## Decision

Support a first-class package-based installation and update path for constrained hosting while retaining Composer as the canonical dependency path.

The constrained-host path will:

1. Use FTP only to place a standalone first-install bootstrap.
2. Transfer complete or patch releases as signed ZIP artifacts through a browser interface after bootstrap.
3. Build dependencies locally from the committed `composer.lock`; production does not resolve dependencies.
4. Materialize complete immutable releases outside the active path.
5. Verify signatures, checksums, runtime requirements, and package structure before activation.
6. Activate releases through an atomic pointer replacement.
7. Run essential operations through shared services exposed by CLI and protected web adapters.
8. Keep one previous release until explicit administrator finalization.
9. Provide an independent, narrowly scoped recovery page for pre-finalization rollback.
10. Treat file-by-file FTP synchronization as a temporary legacy path, not the target operating model.

Full package and exact-base patch support are both part of the first complete implementation. A patch must reconstruct and verify a complete staged target release; it must never edit the active release in place.

## Support Boundary

This path is supported while the project is actively maintained in an environment with these constraints and while the capability contract remains practical to test.

Support is not an unconditional promise to accommodate every FTP host. The required capability contract includes a supported PHP version, ZIP extraction, sufficient browser-upload limits or another explicitly supported package intake, direct filesystem writes, safe rename behavior, private deployment storage, and HTTPS.

Removing this path in the future requires a superseding ADR that addresses both the maintainer's production environment and similarly constrained users. A future move to better hosting may change the maintainer's immediate need, but does not by itself invalidate the broader use case.

## Rationale

- A single artifact avoids FTP's poor performance with thousands of small files.
- Immutable release directories prevent mixed application versions during preparation.
- An atomic pointer provides a small and testable activation boundary without symlink or shell access.
- Signed manifests provide authenticity that checksums alone cannot provide.
- Browser-driven operations satisfy environments covered by ADR 0008.
- Full packages provide a deterministic repair path; patches reduce transfer size only when their exact base is proven.
- The design converts an economic hosting constraint into an explicit, tested capability profile rather than scattering host-specific workarounds through the application.

## Consequences

### Positive

- Installation and routine updates no longer require thousands of FTP operations.
- Operators do not manually determine changed and deleted files.
- Active releases remain unchanged until a replacement is complete and verified.
- The same release artifact can be tested before production and audited afterward.
- Constrained-host users receive a documented and supportable deployment path.
- Recovery becomes an intentional application capability instead of an improvised FTP procedure.

### Negative

- SparkInsight must maintain security-sensitive installer, package, signing, state-machine, and recovery code.
- Browser requests require resumable work because shared hosts impose short execution limits.
- Staging full releases and reconstructing patches temporarily require additional disk space.
- The stable deployment kernel introduces a compatibility surface outside ordinary application releases.
- Code rollback cannot blindly reverse database changes.
- Supporting two deployment paths increases testing and documentation scope.

### Risks

- A vulnerable updater could become a remote-code-execution path.
- A leaked signing key could authorize malicious releases.
- Incorrect ZIP handling could permit traversal, symlink, overwrite, or decompression attacks.
- A failed or incompatible migration could prevent safe code rollback.
- A broken deployment kernel could affect every release.

### Mitigation

- Use detached Ed25519 signatures with the private key kept off production and outside the repository.
- Strictly validate manifests and archive entries before extraction.
- Deny web access to packages, state, logs, releases, and shared secrets.
- Use immutable releases, bounded idempotent state transitions, exclusive locking, and atomic pointer writes.
- Require expand-and-contract migration compatibility until update finalization.
- Keep deployment-kernel scope minimal and test it independently from application releases.
- Require administrator authentication, CSRF protection, HTTPS, audit logging, and explicit confirmation for update mutations.
- Maintain failure-injection, malicious-package, interrupted-operation, recovery, and production-equivalent acceptance tests.

## Alternatives Considered

### Require Better Hosting

Rejected for the current project. It transfers an avoidable recurring cost to a personally funded maintainer and excludes users with similar constraints. It may remain an operational recommendation for deployments that value managed infrastructure over the additional application complexity.

### Continue File-by-File FTP Uploads

Rejected as the target model. It is slow, cumbersome, deletion-prone, difficult to resume safely, and exposes mixed versions.

### Upload Full Trees into Versioned Directories Through FTP

Rejected. It improves activation but still transfers thousands of files and retains FTP's principal failure mode.

### In-Place ZIP Extraction

Rejected. It reduces network operations but can leave a partially overwritten active tree and gives weak rollback behavior.

### Require FTP Credentials in the Admin Interface

Not selected because the verified host permits direct PHP writes under the same owner. An FTP filesystem adapter may be reconsidered for other hosts through a separate decision.

### Download Releases Automatically from a Remote Service

Deferred. Browser upload avoids operating a release service and keeps the deployment flow simple. Remote download can be added later without changing the signed package model.

## Related Decisions

- ADR 0004: Runtime and database platform
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles to Code Architecture
- ADR 0008: CLI-First Operations with Admin UI Fallback

## Links

- RFC 0014: Package-Based Installation and Updates
- RFC 0009: Database Schema Versioning and Migrations
- RFC 0011: Quality-First Development Framework