# ADR 0010: Adopt Lowercase Kebab-Case for Documentation Filenames

Status: Accepted

Date: 2026-08-09

## Context

Documentation filenames should be consistent, predictable, and easy to use across Windows, macOS, and Linux. Mixed naming styles increase the chance of mistakes when typing paths, creating links, or working in case-insensitive environments.

A single convention is especially valuable in a repository that is shared across contributors, operators, and automated tooling.

## Decision

Adopt lowercase kebab-case for new documentation filenames in the repository.

### Convention

Use only lowercase letters, numbers, and hyphens in new documentation file names. Avoid spaces, camelCase, PascalCase, underscores, and mixed-case acronyms.

Examples:

- `setup.md`
- `tdd-guide.md`
- `solid-principles.md`
- `scrivener-setup.md`
- `migration-guidelines.md`

### Scope

This convention applies to documentation files in `docs/`, `rfc/`, and other repository locations used for contributor or operator guidance.

All new documentation files must follow this convention. Existing files should be renamed when they are touched, moved, or otherwise updated as part of normal maintenance.

## Consequences

### Positive

- More predictable and easier-to-scan filenames.
- Fewer case-collision issues on case-insensitive file systems.
- Cleaner links and simpler shell usage.
- Better alignment with common repository and web conventions.

### Negative

- Some existing files will need renaming and related references will need updating.
- A small amount of churn is required during documentation cleanup work.

### Mitigation

- Apply the convention during routine documentation work.
- Update references in the same change when a file is renamed.
- Keep names short, descriptive, and consistent.

## Alternatives Considered

### Keep the Existing Mixed Naming Style

Rejected because it is inconsistent and more error-prone across environments.

### Use Snake Case

Rejected because it is less common and less ergonomic for documentation links and repository conventions.

### Use Title Case or PascalCase

Rejected because it is harder to type consistently and less friendly to case-insensitive systems.

## Related Decisions

- ADR 0008: CLI-First Operations with Admin UI Fallback
- ADR 0009: Support Package-Based Deployment on Constrained Hosting

## Links

- `CONTRIBUTING.md`
- `docs/setup.md`
