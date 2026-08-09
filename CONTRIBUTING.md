# Contributing to SparkInsight

Thank you for contributing to SparkInsight.

This project follows a quality-first approach for all changes:

- TDD is required (ADR 0006, RFC 0011)
- SOLID principles are required (ADR 0007, RFC 0011)
- Conventional Commits are required (ADR 0003)

## Getting Started

### Prerequisites

- PHP 8.1+ (8.5 recommended)
- Composer
- Local database support needed by your target tests and flows

### Setup

1. Clone the repository.
2. Create your local environment file from the example:
   - Bash: `cp .env.example .env`
   - PowerShell: `Copy-Item .env.example .env`
3. Configure required environment values in `.env`.
4. Install dependencies:
   - `composer install`
5. Enable repository-managed Git hooks (recommended):
   - `composer hooks:install`
5. Start the local server:
   - `composer start`

For setup details, see `docs/setup.md`.

## Development Workflow

Use small, focused branches and open pull requests early.

Recommended loop:

1. Write a failing test first.
2. Implement the smallest change to pass.
3. Refactor while keeping tests green.
4. Run the relevant suite locally.
5. Commit with a Conventional Commit message.

## Coding Guidelines

- Keep classes and methods focused on one responsibility.
- Prefer constructor dependency injection over creating dependencies inline.
- Depend on abstractions where practical.
- Keep controllers thin; move business logic to services.
- Avoid hidden global state.
- Favor clear names over clever shortcuts.

Reference guides:

- TDD guide: `docs/tdd-guide.md`
- SOLID guide: `docs/solid-principles.md`
- Import operations guide: `docs/imports.md`
- Migration operations guide: `docs/migrations.md`

Documentation filenames should use lowercase kebab-case (for example `setup.md`, `tdd-guide.md`, `scrivener-setup.md`). The rationale is captured in ADR 0010.

## Commit Conventions

Commit messages must follow Conventional Commits:

- Format: `<type>[optional scope]: <description>`
- Example: `feat(auth): add invitation email restriction`

Common types used in this repository:

- `feat`: new behavior
- `fix`: bug fix
- `refactor`: code restructuring without behavior change
- `test`: test additions or test refactors
- `docs`: documentation-only changes
- `chore`: maintenance tasks

Keep commit descriptions short, specific, and imperative.

Signed commits are required for changes that land on `release/*` branches. Feature work outside release branches may still pass CI with an unsigned commit, but release stabilization work must use a valid GPG-signed commit so the release gate can enforce contributor provenance.

## Testing Requirements

All contributions should include test coverage appropriate for the change.

### Run tests

- Composer script: `composer test`
- Direct PHPUnit (PowerShell-friendly): `php .\vendor\bin\phpunit --configuration phpunit.xml.dist`
- Composer install simulation: `composer test:install-sim`

### Local Git hooks

SparkInsight ships repository-managed hooks in `.githooks/`.

- `pre-commit` runs: `composer hooks:pre-commit`
- `pre-push` runs: `composer hooks:pre-push`

Install once per clone:

- `composer hooks:install`

Temporary bypass (for emergencies only):

- `SKIP_GIT_HOOKS=1 git commit ...`
- `SKIP_GIT_HOOKS=1 git push ...`

`composer test:install-sim` validates that a separate consumer project can resolve this package through Composer before Packagist registration and tags. This check is also enforced in CI.

### Run coverage

- Text report: `composer coverage`
- HTML report: `composer coverage-html`
- Guardrail-only recheck (uses existing `coverage.xml`): `composer coverage:guard`
- v1 readiness check (must have zero exceptions): `composer coverage:guard:v1`
- Coverage HTML is generated into `coverage-report/` and should stay uncommitted.

Method coverage guardrail policy:

- Every method in `src/` must be executed by tests.
- A method may remain uncovered only with a documented exception entry in `docs/coverage-method-exceptions.json`.
- Exceptions are only allowed before version `1.0.0`.
- Every exception must include:
   - `method` in the form `src/Path/File.php::methodName`
   - `reason` with specific technical rationale
   - `owner` responsible for follow-up
- `versionPolicy.allowedBeforeVersion` in `docs/coverage-method-exceptions.json` defines the global cutoff version for exceptions.
- At version `1.0.0` and newer, any exception entry fails the guardrail.
- Stale exceptions (method now covered) also fail CI and must be removed from the exception ledger.

Coverage expectations:

- New or changed project code in `src/` should remain fully covered.
- Do not lower coverage when adding functionality.
- Add or update unit/integration/smoke tests as needed.

Test layout:

- `tests/Unit/` for isolated logic
- `tests/Integration/` for multi-component flows
- `tests/Smoke/` for quick end-to-end sanity checks

## TDD Expectations

SparkInsight uses the Red-Green-Refactor cycle:

1. Red: add a failing test describing the target behavior.
2. Green: implement the minimal code needed to pass.
3. Refactor: improve design while keeping tests green.

When fixing bugs, first add a failing regression test that reproduces the issue.

## SOLID Architecture Expectations

Use SOLID as a review gate for every non-trivial change:

- SRP: one reason to change per class.
- OCP: extend behavior with new types instead of editing stable branching code repeatedly.
- LSP: derived implementations must behave as valid substitutes.
- ISP: avoid wide interfaces that force unused methods.
- DIP: inject collaborators and code against contracts where this improves testability and decoupling.

Practical rules for this codebase:

- Prefer service composition over deep inheritance.
- Inject dependencies through constructors.
- Keep IO and framework concerns at boundaries.
- Keep domain logic deterministic and testable.

## Pull Request Guidelines

Each pull request should include:

- A clear summary of what changed and why.
- Linked RFC/ADR/roadmap context when relevant.
- Tests that prove behavior.
- Notes on migration or setup impact, if any.

Before requesting review:

1. Rebase or merge the latest target branch.
2. Run tests and confirm they pass locally (`composer test` and `composer test:install-sim`).
3. Update docs if behavior or workflow changed.
4. Keep changes scoped to one concern per PR when possible.

If your PR touches imports or migrations:

1. Run `php tools/ci/check_migrations.php`.
2. Run `php vendor/bin/phpunit tests/Unit/Service/MigrationRunnerTest.php`.
3. Run `php si.php db:migrate --status` against a safe local target (SQLite in-memory recommended).
4. Ensure migration naming policy and rollback behavior remain valid.

## Security and Sensitive Data

- Never commit secrets or real credentials.
- Do not commit `.env`.
- Avoid logging tokens, invitation codes intended for production use, or OAuth secrets.
- Treat authentication, session, CSRF, and role-enforcement changes as security-sensitive and include targeted regression tests.

## Issues and Feature Requests

When opening an issue, include:

- Expected behavior
- Actual behavior
- Reproduction steps
- Environment details (PHP version, OS, database)
- Relevant logs or stack traces (redacted)

For feature requests, link the related RFC when possible.

## Community and Communication

Use repository issues and pull request discussions as the primary communication channel.

If a change requires architectural direction, reference or propose an ADR/RFC entry so decisions stay discoverable.