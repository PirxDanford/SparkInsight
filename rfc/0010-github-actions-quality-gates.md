# RFC 0010: GitHub Actions Quality Gates

Status: Draft

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

## Links

- GitHub Actions Documentation: https://docs.github.com/en/actions
- PHPUnit: https://phpunit.de/
- PHPStan: https://phpstan.org/
- RFC 0011: Quality-First Development Framework
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles