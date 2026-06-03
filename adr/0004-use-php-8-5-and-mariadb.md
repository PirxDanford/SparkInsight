# ADR 0004: Use PHP 8.5 for Web Frontend and MariaDB for Database

Status: Accepted

Date: 2026-04-18

## Context

The project requires a web frontend for presenting content to reviewers and managing feedback. Due to existing webhosting constraints, we are limited to PHP 8.5 for server-side scripting and an unknown version of MariaDB for the database. This setup must support the review lifecycle, user authentication, and content versioning without requiring custom infrastructure.

## Decision

Adopt PHP 8.5 as the primary language for the web frontend and MariaDB for data storage.

- Use PHP 8.5 features like enums, readonly properties, and improved type system for robust development.
- Leverage MariaDB for relational data management, ensuring compatibility with the hosting environment.
- Implement the application using a lightweight PHP framework (e.g., Slim or custom) to keep it maintainable.

## Consequences

- **Positive**: Aligns with hosting constraints; PHP 8.5 offers modern features for better code quality; MariaDB provides reliable SQL storage.
- **Negative**: Limited to PHP ecosystem; potential version incompatibilities with MariaDB; may require workarounds for advanced features not available in hosting.
- **Mitigation**: Test thoroughly against the hosting environment; use PDO for database abstraction; plan for potential upgrades.

## Links

- PHP 8.5: https://www.php.net/releases/8.5/en.php
- MariaDB: https://mariadb.org/
- ADR 0006: Adopt Test-Driven Development (implement with unit and integration tests)
- ADR 0007: Apply SOLID Principles (clean architecture for data access layer)