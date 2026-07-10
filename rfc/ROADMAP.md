# RFC Roadmap & Tracker

**Last Updated:** 2026-07-05

## v1.0.0 Release Backlog

This file is the implementation backlog for preparing, stabilizing, and tagging the `v1.0.0` release.

RFCs capture the feature ideas, decisions, and scope. This tracker turns those RFCs into the concrete work that still needs to be tackled.

The chapter structure below is not a delivery schedule. It groups related work by dependency and subject area so future releases can be planned the same way: RFC first, backlog second, implementation third.

All development must follow the Quality-First Development Framework (RFC 0011, Chapter 0), which establishes Test-Driven Development and SOLID Principles as foundational practices. This ensures code reliability, maintainability, and testability from the start.

Implementation chapters are listed in dependency order, not in expected release order:

0. **Chapter 0: Quality Framework** (RFC 0011) — Complete
1. **Chapter 1: Setup & Authentication** (RFC 0006) — In progress
2. **Chapter 2: Data Management** (RFCs 0007, 0009) — Complete
3. **Chapter 3: Reviewer Experience** (RFC 0008) — Planned
4. **Chapter 4: Author Experience** (RFCs 0001, 0002) — Planned
5. **Chapter 5: Release Wrap-up & Contribution** (RFCs 0003, 0004, 0005, 0010, 0012) — Planned

## How This Tracker Works

- RFCs define what should exist and why.
- This roadmap lists the concrete work that remains to be done.
- Headings are work chapters, not promises about delivery dates.
- Status values describe implementation progress only.
- Release Readiness is the cutover checklist for a release branch, not a separate roadmap.

---

### Chapter 0: Quality Framework

- **Primary RFC:** 0011 — Quality-First Development Framework
- **Status:** Complete
- **Chapter focus:** TDD, SOLID, quality enforcement
- **Chapter note:** This chapter defines the quality gate for the entire `v1.0.0` release process.
- **Chapter deliverables:**
  - Added TDD guide: `docs/TDD-Guide.md`
  - Added SOLID principles guide: `docs/SOLID-Principles.md`
  - Added test templates: `docs/test-templates/`
  - Refactored `GenerateInvitationCommand` for dependency injection and testability
  - Updated `InvitationService` SQL to use `CURRENT_TIMESTAMP` for SQLite compatibility
  - Cleaned PHP 8.5 compatibility by removing deprecated reflection usage

---

### Chapter 1: Setup & Authentication

- **Primary RFC:** 0006 — User management & authentication
- **Status:** Complete
- **Scope:** Invitation onboarding, admin invitation management, OAuth login/signup, user role assignment
- **Chapter note:** This chapter is part of the `v1.0.0` release baseline and must remain covered by the final release branch.
- **Chapter deliverables:**
  - Admin invitation creation implemented
  - Admin invitation listing and filtering support added
  - Invitation validation and one-time invite flow implemented
  - Role assignment and signup callback flow wired
  - Local PHPUnit verification passing
  - Session and cookie hardening completed
  - CSRF protection applied to admin POST forms and invitation updates
  - OAuth state validation and invitation signup flow verified

---

### Chapter 2: Data Management

- **Primary RFCs:** 0007, 0009
- **Status:** Complete
- **Scope:** Database schema versioning, migrations, data import, and import quality
- **Chapter note:** This chapter defines the schema and import baseline that will be consolidated into the release branch migration history before tagging `v1.0.0`.
- **Chapter deliverables:**
  - Database schema initialized for users, content versions, and reviews
  - Migration tooling and schema versioning implemented
  - Import validation and rollback behavior defined
  - Scrivener backup-based import pipeline implemented (`.scrivx` + `Files/Data` parsing with hierarchy/order preservation)
  - Scrivener backup imports verified as persisted immutable content versions (FDX fallback retained for compatibility)
  - Backend policy set for same-email multi-provider identities (manual link, no auto-link)
  - Backend identity foundation added for multi-provider accounts (`oauth_identities` migration and service support)
  - RFC 0007 and RFC 0009 implementation status reviewed and marked accordingly
  - Chapter 2 security review completed for data integrity, import validation, and migration safety

---

### Chapter 3: Reviewer Experience

- **Primary RFC:** 0008
- **Status:** Complete
- **Scope:** Reviewer dashboard, comment display, review submission

- **Chapter note:** These features are release-blocking for `v1.0.0` and must be complete before the release branch can be frozen.
- **Chapter deliverables:**
  - Implemented reviewer dashboard and reviewer drill-in reader experience for assigned items (`/dashboard/review`, `/dashboard/review/{id}`)
  - Added reviewer note submission with status handling and persisted anchor modes (whole-item, position, range)
  - Added reviewer-side filtering, sorting, folder hierarchy navigation cues, and stable queue return context
  - Added remembered reader panel visibility preference for desktop reviewer usage
  - Completed RFC 0008 closure evidence and marked RFC 0008 as Implemented for v1.0.0 reviewer scope
  - Clarified scope boundary: explicit "resolved by author" actor attribution is owned by RFC 0002 / Chapter 4
  - Completed desktop acceptance verification on 2026-07-05 (`php vendor/bin/phpunit tests/Unit/Controller/DashboardControllerTest.php tests/Integration/Controller/DashboardReviewerFlowIntegrationTest.php` => `OK (20 tests, 100 assertions)`)
  - Completed Chapter 3 security review on 2026-07-05, including reviewer data access and submit/display controls; added traversal hardening for metadata source fallback and regression test coverage
  - Deferred tablet/mobile reviewer responsiveness to RFC 0013 (v1.1 backlog)

---

### Chapter 4: Author and Admin Experience

- **Primary RFCs:** 0001, 0002
- **Status:** Complete
- **Scope:** Author dashboard, review resolution, feedback lifecycle

- **Chapter note:** These workflows must be complete and verified before the `v1.0.0` release branch is tagged.
- **Chapter deliverables:**
  - Added PDF export for single items and selected item sets
  - Implemented author dashboard review resolution workflows, including explicit `resolved`, `still relevant`, and `ignored` actions
  - Added reviewer-visible author-attributed resolution metadata in reviewer history, with controller and rendering coverage for RFC 0002 ownership
  - Surfaced reviewer feedback status and unresolved-location handling to authors, including fallback context for remapped or missing anchors
  - Implemented import-time reviewer anchor remapping for updated Scrivener versions, with confidence and failure states
  - Extended author resolution context to show original target context, current changed context, and version linkage with regression coverage
  - Added an append-only review resolution audit log so author decisions are preserved immutably as decision history events
  - Added reviewer default panel visibility preference and completed related author/reviewer reader mode support
  - Added administrator settings UI coverage for configurable application values, including invitation validity defaults
  - Added CLI support to override invitation validity duration for `invite:generate`
  - Marked RFC 0001 and RFC 0002 as implemented in the roadmap and source RFC tracking
  - Completed Chapter 4 security review on 2026-07-10, including author access, resolution integrity, and remap-context privacy hardening with regression coverage

---

### Chapter 5: Release Wrap-up & Contribution

- **Primary RFCs:** 0003, 0004, 0005, 0010, 0012
- **Status:** Planned
- **Scope:** Contribution documentation, Scrivener docs, release readiness, enforcement of quality gates, release branch stabilization

- **Chapter note:** This chapter is the final `v1.0.0` release checklist, not a separate post-release roadmap.

#### Chapter 5 Open Items

- [ ] Merge the Users, Invitations and Settings tab into one Admin tab for a unified Admin Dashboard
- [ ] Enable custom names for display for users, which are not related to the user accounts
- [ ] Enable a basic management in the admin interface for users and invitations
- [ ] Are there any CLI commands we will need in a production system? If yes we need to enable them in some maintenance page or on the admin page
- [ ] Complete end-to-end OAuth provider QA for Google, LinkedIn and Facebook
- [ ] Add import and migration documentation (RFC 0012)
- [ ] Add migration CI checks and migration-runner usage docs (RFC 0012)
- [ ] Add account-linking UX for multi-provider identities (same-email conflict messaging, provider management UI)
- [ ] Complete Scrivener backup import workflow documentation
- [ ] Finalize Scrivener setup documentation
- [ ] Document GitHub, Google, LinkedIn and Facebook OAuth app setup for a real website (redirect URIs, app registration, client secret handling)
- [ ] Create CONTRIBUTING.md with TDD and SOLID guidance
- [ ] Define release readiness checklist and quality gate signoff
- [ ] Complete RFC 0010 implementation for GitHub Actions quality gates
- [ ] Add commit signing check to RFC 0010 quality gate requirements
- [ ] Add CI enforcement for code coverage, static analysis, and style checks
- [ ] Revisit reviewer note action button order and visual styling (Save, Clear, Delete) for final UX polish.
- [ ] Add CI quality gate to enforce migration naming policy (`001_initial_schema.sql` baseline, `dev_only_*.sql` for initial-development snapshots, numbered `002+` reserved for release upgrades)
- [ ] Check if RFCs 3, 4, 5, 10 and 12 can be marked as implemented, add more todos to tackle what is missing above also keep this line then stop, else mark the rfcs source files accordingly as well as content in the roadmap and then tick this item
- [ ] Perform Chapter 5 security review, including release readiness, contributor enforcement, and deployment controls, only work on this item after every other item is done
- [ ] Check if "## Cross-phase security review checklist" can be deleted now, do we need to put any security related info into documentation?
- [ ] Freeze the release branch and collapse the migration history so only `database/migrations/001_initial_schema.sql` remains as the release baseline
- [ ] Verify the release branch still reproduces the current schema and data flow from the single baseline migration
- [ ] Confirm the tagged release branch contains all release documentation updates, including migration and setup guidance
- [ ] When `release/v1.0.0` is prepared for tagging, collapse the database migration history to the single baseline migration `database/migrations/001_initial_schema.sql`
- [ ] Tidy up Chapter 5 documentation and checklist style, meaning to delete the "### Chapter 5 Open Items" section and instead fill in a "**Chapter deliverables:**" section as in chapters 0 to 4

### Release Readiness

- **Goal:** prepare `release/v1.0.0` for tagging and cutover
- **Focus:** schema versioning, branch freeze, testing, docs, security, and signoff
- **Note:** This checklist is a cutover gate. It does not express a schedule.

#### Release Readiness Checklist

- [ ] Confirm all Chapter 0-5 items required for `v1.0.0` are complete on the release branch
- [ ] Collapse database migration history for the release branch so `database/migrations/001_initial_schema.sql` is the baseline migration
- [ ] Verify the final schema can be installed from the release branch without depending on development-only migration history
- [ ] Ensure release docs explain how migration versioning works on the development branch versus the tagged release branch
- [ ] Run the full test suite and any release-specific validation commands
- [ ] Re-check reviewer and author flows end to end on the release branch
- [ ] Complete the final security review, including CSRF, session handling, OAuth state, admin enforcement, and release controls
- [ ] Confirm quality gates, commit signing, and CI checks are enforced for the release branch
- [ ] Review setup, contribution, and migration documentation for the final tag
- [ ] Tag `v1.0.0` only after the release branch is stable and the checklist is fully green

#### Post-Release Maintenance

- [ ] Keep future development on a new branch after `v1.0.0` is tagged
- [ ] Re-open the roadmap only for post-release follow-up items and v1.0.1 planning

## v1.1 Backlog

- [ ] Add a reader theme switcher with Paper as default and at least Dark and Green Classic alternatives.
- [ ] Add client-side automated backup tooling for Scrivener workflows to reduce manual backup/export steps and support more automated ingestion preparation.
- [ ] Complete RFC 0013 scope for reviewer cross-device experience (tablet/mobile layout, touch-first controls, and acceptance pass).
- [ ] Make CLI deletion of content with reviewer notes intentionally hard (strong safeguards/explicit confirmations required).
- [ ] Move deleted reviewer notes to a separate archive table (instead of hard delete) and provide an author-facing option to review deleted notes.
- [ ] Re-introduce author-side range context expansion in a usable reviewer-style embedded reader view, with neat in-text highlight and surrounding content (replace the current non-usable expansion approach).
- [x] Created RFC 0013 to formalize v1.1 reviewer cross-device scope.

---

## Cross-phase security review checklist

This checklist is intentionally cross-phase; these checks should be verified during each relevant phase security review.


- [ ] Audit CSRF protections for all state-changing POST endpoints
- [ ] Confirm session cookie settings are secure, HttpOnly, and SameSite-aware
- [ ] Verify OAuth state token validation and session lifecycle
- [ ] Confirm invitation code binding, expiry, and email verification
- [ ] Validate admin role enforcement on all admin routes
- [ ] Ensure RFC 0010 quality gates include commit signing and CI checks

---

## RFC Status Overview

| # | Title | Status | Chapter | Priority | Notes |
|---|-------|--------|---------|----------|-------|
| 0011 | Quality-First Development Framework | Implemented | 0 | **CRITICAL** | Foundation for all chapters (ADR 0006, ADR 0007) |
| 0010 | GitHub Actions quality gates | Draft | 5 | High | Enforces ADR 0006 and ADR 0007, includes commit signing, and supports release readiness |
| 0013 | Reviewer Cross-Device Experience (v1.1) | Draft | v1.1 | Medium | Defines tablet/mobile reviewer UX and acceptance criteria; keeps v1.0.0 desktop-focused |
| 0006 | User management & authentication | Implemented | 1 | High | Completed with invitation-based signup and OAuth login flows |
| 0007 | Data importing method | Implemented | 2 | High | Implemented: `src/Service/ContentImportService.php`, `src/Command/ScrivenerImportCommand.php`, baseline schema in `database/migrations/001_initial_schema.sql` (dev snapshot retained as `database/migrations/dev_only_add_content_versions_and_reviews.sql`) |
| 0009 | Database schema versioning | Implemented | 2 | High | Implemented: `src/Service/MigrationRunner.php`, `src/Command/MigrateDbCommand.php`, migrations in `database/migrations/` |
| 0008 | Initial reviewer display | Implemented | 3 | High | Implemented for v1.0.0 reviewer display scope (dashboard, filtering, panel preference, note submission, anchor persistence, queue-state persistence); explicit author-attributed resolution semantics are delegated to RFC 0002/Chapter 4 |
| 0001 | Feedback lifecycle | Implemented | 4 | Medium | Implemented in Chapter 4, including explicit author decisions (`resolved`, `still relevant`, `ignored`), immutable review decision history, and original-vs-current context/version linkage |
| 0002 | Author-driven review resolution | Implemented | 4 | Medium | Implemented in Chapter 4 with author-driven status handling, reviewer-visible author-attributed resolution metadata, and append-only audit trail coverage |
| 0003 | Scrivener ingestion proof of concept | Implemented | 2 | Low | Completed with backup-first ingestion; export-only paths retained as non-primary alternatives |
| 0004 | Scrivener setup documentation | Draft | 5 | Low | Defer until Chapter 5 |
| 0005 | Contribution documentation | Draft | 5 | High | Create CONTRIBUTING.md in Chapter 5; must document TDD/SOLID |

---

## Status Definitions

- **Draft**: Initial proposal, may have open questions
- **In Review**: Feedback gathered, design refined
- **Accepted**: Consensus reached, ready for implementation
- **Implemented**: Feature coded and tested
- **Deployed**: Live in production
- **On Hold**: Blocked or deprioritized

---

## ADR Integration

Architectural decisions guide all RFC implementations:

- **ADR 0001**: Use minimal Rust ADR system (metadata tracking)
- **ADR 0002**: Scrivener backup project import source (Chapter 2)
- **ADR 0003**: Conventional commits & semantic versioning (all chapters; test changes use `test:` prefix)
- **ADR 0004**: PHP 8.5 & MariaDB (all chapters)
- **ADR 0005**: Slim and League OAuth2 Client (Chapter 1 authentication)
- **ADR 0006**: Adopt Test-Driven Development (Chapter 0, applies to all chapters) — **FOUNDATIONAL**
- **ADR 0007**: Apply SOLID Principles (Chapter 0, applies to all chapters) — **FOUNDATIONAL**

---

## How to Update This Tracker

- Update status when RFCs move through chapters
- Move RFCs between chapters as priorities shift
- Link to implementation branches/PRs in notes
- Add blocking issues to notes field
