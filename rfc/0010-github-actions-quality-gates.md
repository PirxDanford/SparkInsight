# RFC 0010: GitHub Actions Quality Gates

Status: Implemented

Date: 2026-04-20

## Summary

Introduce GitHub Actions as the primary CI/CD platform for automated quality gates on all commits, ensuring code quality, test execution, and other checks before merging. The setup should be extensible to accommodate future automation needs like deployments, security scans, and performance monitoring.

## Problem

Currently, there are no automated checks on commits or pull requests. Code quality, test execution, and other validations happen manually or sporadically, leading to potential issues slipping into the main branch. As the project grows, maintaining quality without automation becomes unsustainable.

## Proposal

1. Create a GitHub Actions workflow (`.github/workflows/ci.yml`) that triggers on:
   - Push to main branch
   - Pull requests targeting main branch
   - Manual dispatch for testing

2. Initial quality gates to include:
   - PHP syntax checking
   - PHPUnit test execution
   - Code coverage reporting (when driver available)
   - Composer dependency validation
   - Migration naming policy enforcement for `database/migrations/` (allow `001_initial_schema.sql` as the only initial-development numbered baseline, require `dev_only_*.sql` for chapter-era development snapshots, and reserve next numbered migrations for release upgrades like `002_upgrade_v1_0_to_v1_1.sql`)
   - Basic security scans (e.g., via tools like PHPStan or Psalm if added later)

3. Workflow structure:
   - Use matrix builds for multiple PHP versions if needed (initially focus on PHP 8.5)
   - Cache Composer dependencies and vendor folder
   - Store test results and coverage reports as artifacts
   - Fail the build on test failures or low coverage thresholds (configurable)

4. Extensibility design:
   - Modular job definitions allowing easy addition of new checks
   - Environment variables for configuration (e.g., coverage thresholds)
   - Separate workflows or jobs for different purposes (CI vs CD)
   - Integration with GitHub features like status checks, branch protection rules

5. Branch protection rules:
   - Require status checks to pass before merging
   - Require up-to-date branches for PRs
   - Prevent direct pushes to main (require PRs)

## Why this works

GitHub Actions provides native integration with GitHub repositories, making it the most straightforward choice for this project. It ensures consistent quality checks across all contributions while being flexible enough to grow with the project's needs. Starting with basic gates allows incremental improvement without overwhelming the initial setup.

## Open questions

- What should be the minimum code coverage threshold for passing builds?
- Should we include additional tools like PHPStan for static analysis immediately, or add them later?
- How to handle the current limitation with code coverage drivers on Windows development environments?
- Should the workflow also run on feature branches or only on PRs/main?
- Integration with external services (e.g., code quality badges, notifications)?

## Integration with RFC 0011

This RFC is the CI/CD implementation vehicle for **RFC 0011: Quality-First Development Framework**.

- GitHub Actions will enforce **ADR 0006** (Test-Driven Development) via:
  - PHPUnit test execution (all tests must pass)
  - Code coverage verification (100% for project code)
  - Coverage regression detection (prevent decreases)

- GitHub Actions will support **ADR 0007** (SOLID Principles) via:
  - PHPStan static analysis (type safety, potential bugs)
  - Code style checks (PSR-12 consistency)
  - Dependency quality validation

## Implementation (v1.0.0)

Completed 2026-07-12. The following quality gates have been implemented:

### Main CI Workflow (`.github/workflows/ci.yml`)

- **Commit Signing Verification:** Validates GPG signatures and fails `release/*` branch builds when the latest commit is unsigned
- **PHP Syntax Checking:** Lints all PHP files for syntax errors
- **Composer Validation:** Ensures `composer.json` is valid and dependencies are locked
- **PHPUnit Tests:** Runs full test suite with coverage reporting (PCOV driver)
- **Code Coverage:** Enforces minimum 90% coverage threshold for project code, with artifact storage for review
- **PHPStan Static Analysis:** Level max analysis with 443 baseline errors captured for incremental improvement (type safety, potential bugs)
- **PHP-CS-Fixer Style Checks:** Dry-run validation against PSR-12 and modern PHP standards
- **Coverage Reporting:** Generates coverage reports as artifacts and posts summary comments on PRs

### Migration Checks Workflow (`.github/workflows/migration-checks.yml`)

Enhanced with:
- Commit signing verification with strict enforcement on `release/*` branches
- Existing migration naming policy enforcement (validates `001_initial_schema.sql`, `dev_only_*.sql` patterns)

### Tooling & Configuration

- **PHPStan** (v1.12.33): Configured with max level type checking, baseline file for existing issues
- **PHP-CS-Fixer** (v3.95.13): PSR-12 + PHP 8.1+ migration rules, ~90% of available fixers enabled
- **Composer Dependencies:** Both tools added as `require-dev` dependencies
- **Memory Optimization:** PHPStan runs with `--memory-limit=512M` to handle large codebase analysis

### Deliverables

- [x] `.github/workflows/ci.yml` — Main CI pipeline with all quality gates
- [x] `.github/workflows/migration-checks.yml` — Enhanced with commit signing
- [x] `phpstan.neon` + `phpstan-baseline.neon` — Type checking configuration with baseline
- [x] `.php-cs-fixer.php` — Code style enforcement configuration
- [x] `composer.json` updated with PHPStan and PHP-CS-Fixer dependencies
- [x] Coverage reporting with PR comments
- [x] Artifact storage for coverage reports (HTML + text formats)

### Coverage Threshold Rationale

- **90%** chosen as pragmatic threshold balancing:
  - Enforces strong coverage discipline (ADR 0006)
  - Accommodates Windows dev environment limitations mentioned in RFC
  - Allows incremental path to 100% without blocking releases
  - Can be increased to 95-100% as codebase matures

### Type Safety Progress

- **443 baseline errors** captured from initial PHPStan run
- Baseline allows new contributions without regression while enabling incremental type safety improvements
- Future work can remove items from baseline as code is refactored

### Next Steps (v1.1+)

- Upgrade to PHPStan 2.x (mentioned in output; offers level 10, ~50-70% less memory)
- Add SARIF output for GitHub Security tab integration
- Consider adding Psalm as complementary type checker
- Expand to include mutation testing (Infection PHP)
- Add automated code coverage trend tracking

## Links

- GitHub Actions Documentation: https://docs.github.com/en/actions
- PHPUnit: https://phpunit.de/
- PHPStan: https://phpstan.org/
- PHP-CS-Fixer: https://cs.symfony.com/
- RFC 0011: Quality-First Development Framework
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles