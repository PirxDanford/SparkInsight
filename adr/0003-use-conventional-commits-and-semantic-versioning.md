# ADR 0003: Adopt Conventional Commits and Semantic Versioning

Status: Proposed

Date: 2026-04-18

## Context

The project needs a consistent commit message format to enable automated versioning, changelog generation, and better collaboration. Semantic versioning ensures clear communication of changes to users and maintainers. Without standardized practices, releases can be inconsistent, and understanding change impact becomes difficult.

## Decision

Adopt Conventional Commits for all commit messages and Semantic Versioning for releases.

- Use Conventional Commits format: `<type>[optional scope]: <description>` (e.g., `feat: add user authentication`).
- Follow Semantic Versioning: MAJOR.MINOR.PATCH, where:
  - MAJOR: Breaking changes
  - MINOR: New features (backward compatible)
  - PATCH: Bug fixes (backward compatible)
- Integrate with tooling like `semantic-release` or `commitizen` for automation if adopted later.

## Consequences

- **Positive**: Enables automated changelog and version bumping; improves commit readability; aligns with industry standards.
- **Negative**: Requires discipline from contributors; initial learning curve.
- **Mitigation**: Provide guidelines in CONTRIBUTING.md; use commit hooks or CI to enforce format.

## Links

- Conventional Commits: https://conventionalcommits.org/
- Semantic Versioning: https://semver.org/
- Semantic Release: https://github.com/semantic-release/semantic-release
- ADR 0006: Adopt Test-Driven Development (test commits use `test:` prefix)
- ADR 0007: Apply SOLID Principles (refactoring commits use `refactor:` prefix)</content>
<parameter name="filePath">c:\Projekte\SparkInsight\adr\0003-use-conventional-commits-and-semantic-versioning.md