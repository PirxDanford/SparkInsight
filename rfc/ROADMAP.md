# RFC Roadmap & Tracker

**Last Updated:** 2026-06-03

## Quick Start: Development Priority Order

All development must follow the Quality-First Development Framework (RFC 0011, Phase 0) which establishes Test-Driven Development and SOLID Principles as foundational practices. This ensures code reliability, maintainability, and testability from the start.

Implementation should follow this sequence:

0. **Phase 0: Quality Framework** (RFC 0011) — Complete
1. **Phase 1: Setup & Authentication** (RFC 0006) — In progress
2. **Phase 2: Data Management** (RFCs 0007, 0009) — Complete
3. **Phase 3: Reviewer Experience** (RFC 0008) — Planned
4. **Phase 4: Author Experience** (RFCs 0001, 0002) — Planned
5. **Phase 5: Wrap-up & Contribution** (RFCs 0003, 0004, 0005, 0010) — Planned

---

## Phase 0: Quality Framework

- **Primary RFC:** 0011 — Quality-First Development Framework
- **Status:** Complete
- **Phase focus:** TDD, SOLID, quality enforcement
- **Phase-specific deliverables:**
  - Added TDD guide: `docs/TDD-Guide.md`
  - Added SOLID principles guide: `docs/SOLID-Principles.md`
  - Added test templates: `docs/test-templates/`
  - Refactored `GenerateInvitationCommand` for dependency injection and testability
  - Updated `InvitationService` SQL to use `CURRENT_TIMESTAMP` for SQLite compatibility
  - Cleaned PHP 8.5 compatibility by removing deprecated reflection usage

---

## Phase 1: Setup & Authentication

- **Primary RFC:** 0006 — User management & authentication
- **Status:** Complete
- **Scope:** Invitation onboarding, admin invitation management, OAuth login/signup, user role assignment
- **Phase deliverables:**
  - Admin invitation creation implemented
  - Admin invitation listing and filtering support added
  - Invitation validation and one-time invite flow implemented
  - Role assignment and signup callback flow wired
  - Local PHPUnit verification passing
  - Session and cookie hardening completed
  - CSRF protection applied to admin POST forms and invitation updates
  - OAuth state validation and invitation signup flow verified

---

## Phase 2: Data Management

- **Primary RFCs:** 0007, 0009
- **Status:** Complete
- **Scope:** Database schema versioning, migrations, data import, and import quality
- **Phase deliverables:**
  - Database schema initialized for users, content versions, and reviews
  - Migration tooling and schema versioning implemented
  - Import validation and rollback behavior defined
  - Scrivener-based FDX test import pipeline implemented
  - FDX imports verified as persisted immutable content versions
  - Backend policy set for same-email multi-provider identities (manual link, no auto-link)
  - Backend identity foundation added for multi-provider accounts (`oauth_identities` migration and service support)
  - RFC 0007 and RFC 0009 implementation status reviewed and marked accordingly
  - Phase 2 security review completed for data integrity, import validation, and migration safety

---

## Phase 3: Reviewer Experience

- **Primary RFC:** 0008
- **Status:** Planned
- **Scope:** Reviewer dashboard, comment display, review submission

### Phase 3 Open Todos

- [ ] Build basic reviewer dashboard UI prototype
- [ ] Add comment submission and status display
- [ ] Enable reviewer-side filtering and navigation
- [ ] Check if RFC 8 can be marked as implemented, add more todos to tackle what is missing, else mark accordingly
- [ ] Perform Phase 3 security review, including reviewer data access and submit/display controls, only work on this item after every other item is done
- [ ] Tidy up Phase 3 documentation and checklist style, meaning to delete the "### Phase 3 Open Todos" chapter and instead fill in a "**Phase deliverables:**" section as in phases 0 to 2

---

## Phase 4: Author and Admin Experience

- **Primary RFCs:** 0001, 0002
- **Status:** Planned
- **Scope:** Author dashboard, review resolution, feedback lifecycle

### Phase 4 Open Todos

- [ ] Implement author review resolution workflows
- [ ] Surface reviewer feedback status to authors
- [ ] Integrate Scrivener sync planning notes
- [ ] Create a settings interface for administrators, move as many config values in there as feasible e.g. the default invitation validity duration (1 week by default)
- [ ] For the CLI make the invitation validity duration for invite:generate overridable via parameter
- [ ] Check if RFCs 1 and 2 can be marked as implemented, add more todos to tackle what is missing, else mark accordingly
- [ ] Perform Phase 4 security review, including author access, review resolution, and feedback privacy, only work on this item after every other item is done
- [ ] Tidy up Phase 4 documentation and checklist style, meaning to delete the "### Phase 4 Open Todos" chapter and instead fill in a "**Phase deliverables:**" section as in phases 0 to 3

---

## Phase 5: Wrap-up & Contribution

- **Primary RFCs:** 0003, 0004, 0005, 0010, 0012
- **Status:** Planned
- **Scope:** Contribution documentation, Scrivener sync docs, release readiness, enforcement of quality gates

### Phase 5 Open Todos

- [ ] Complete end-to-end OAuth provider QA for Google, LinkedIn and Facebook
- [ ] Add import and migration documentation (RFC 0012)
- [ ] Add migration CI checks and migration-runner usage docs (RFC 0012)
- [ ] Add account-linking UX for multi-provider identities (same-email conflict messaging, provider management UI)
- [ ] Complete Scrivener external folder sync implementation documentation
- [ ] Finalize Scrivener setup documentation
- [ ] Document GitHub, Google, LinkedIn and Facebook OAuth app setup for a real website (redirect URIs, app registration, client secret handling)
- [ ] Create CONTRIBUTING.md with TDD and SOLID guidance
- [ ] Define release readiness checklist and quality gate signoff
- [ ] Complete RFC 0010 implementation for GitHub Actions quality gates
- [ ] Add commit signing check to RFC 0010 quality gate requirements
- [ ] Add CI enforcement for code coverage, static analysis, and style checks
- [ ] Document quality gate requirements in project README or CONTRIBUTING guidelines
- [ ] Check if RFCs 3, 4, 5, 10 and 12 can be marked as implemented, add more todos to tackle what is missing, else mark accordingly
- [ ] Perform Phase 5 security review, including release readiness, contributor enforcement, and deployment controls, only work on this item after every other item is done
- [ ] Check if "## Cross-phase security review checklist" can be deleted now, do we need to put any security related info into documentation?
- [ ] Tidy up Phase 5 documentation and checklist style, meaning to delete the "### Phase 5 Open Todos" chapter and instead fill in a "**Phase deliverables:**" section as in phases 0 to 4

## Phase Cleanup

- [ ] Perform a final roadmap tracker review for consistent wording and section structure after Phase 5 completion
- [ ] Consider the future format of this file, likely it will be related to GitHub issues and how contribution will work over all

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

| # | Title | Status | Phase | Priority | Notes |
|---|-------|--------|-------|----------|-------|
| 0011 | Quality-First Development Framework | Implemented | 0 | **CRITICAL** | Foundation for all phases (ADR 0006, ADR 0007) |
| 0010 | GitHub Actions quality gates | Draft | 5 | High | Enforces ADR 0006 and ADR 0007, includes commit signing, and supports release readiness |
| 0006 | User management & authentication | Implemented | 1 | High | Completed with invitation-based signup and OAuth login flows |
| 0007 | Data importing method | Implemented | 2 | High | Implemented: `src/Service/ContentImportService.php`, `src/Command/ScrivenerImportCommand.php`, `database/migrations/003_add_content_versions_and_reviews.sql` |
| 0009 | Database schema versioning | Implemented | 2 | High | Implemented: `src/Service/MigrationRunner.php`, `src/Command/MigrateDbCommand.php`, migrations in `database/migrations/` |
| 0008 | Initial reviewer display | Draft | 3 | High | MVP completion; implement with TDD |
| 0001 | Feedback lifecycle | Draft | 4 | Medium | Open questions on reviewer closure |
| 0002 | Author-driven review resolution | Draft | 4 | Medium | Depends on Phase 3 completion |
| 0003 | Scrivener external folder sync | Accepted | 5 | Low | Infrastructure-dependent, defer to Phase 5 |
| 0004 | Scrivener setup documentation | Draft | 5 | Low | Defer until Phase 5 |
| 0005 | Contribution documentation | Draft | 5 | High | Create CONTRIBUTING.md in Phase 5; must document TDD/SOLID |

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
- **ADR 0002**: Scrivener sync via external folder (Phase 5)
- **ADR 0003**: Conventional commits & semantic versioning (all phases; test changes use `test:` prefix)
- **ADR 0004**: PHP 8.5 & MariaDB (all phases)
- **ADR 0005**: Slim and League OAuth2 Client (Phase 1 authentication)
- **ADR 0006**: Adopt Test-Driven Development (Phase 0, applies to all phases) — **FOUNDATIONAL**
- **ADR 0007**: Apply SOLID Principles (Phase 0, applies to all phases) — **FOUNDATIONAL**

---

## How to Update This Tracker

- Update status when RFCs move through phases
- Move RFCs between phases as priorities shift
- Link to implementation branches/PRs in notes
- Add blocking issues to notes field
