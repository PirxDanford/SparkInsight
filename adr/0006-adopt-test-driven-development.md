# ADR 0006: Adopt Test-Driven Development (TDD)

Status: Accepted

Date: 2026-05-20

## Context

The SparkInsight project handles critical feedback workflows for manuscript review. Code quality, reliability, and maintainability are essential to prevent regressions and ensure trust in the feedback system. Previous development showed gaps in test coverage, particularly in complex business logic paths (OAuth flows, user management, invitation handling). Ad-hoc testing and post-hoc test writing have resulted in incomplete coverage and brittle code that breaks unexpectedly during refactoring.

## Decision

Adopt Test-Driven Development (TDD) as the standard development practice for all new features and bug fixes.

### TDD Process

1. **Red**: Write a failing test that describes the desired behavior
2. **Green**: Write minimal code to make the test pass
3. **Refactor**: Improve code quality while keeping tests green

### Guidelines

- Write tests before implementing features
- Aim for 100% code coverage on project code (excluding vendor libraries)
- Use PHPUnit for unit tests (fast, isolated, mocked dependencies)
- Use integration tests for workflows requiring database or service interaction
- Each test should have a single responsibility and clear assertion
- Test both happy paths and error/edge cases

### Tooling

- **PHPUnit**: Composer-locked unit and integration testing framework
- **SQLite in-memory**: Fast database for integration tests
- **Coverage Reports**: Generated via `composer coverage` command
- **CI/CD Integration**: Tests run on every commit; coverage must not decrease

## Consequences

### Positive
- Reduced bugs and regressions; easier to refactor with confidence
- Living documentation: tests show how code should behave
- Better design: TDD naturally encourages modular, testable code
- Faster debugging: tests identify exactly what broke and why
- Higher confidence in critical paths (OAuth, user management, data import)

### Negative
- Initial development velocity may appear slower (offset by reduced debugging time)
- Requires discipline and training for team members unfamiliar with TDD
- Some code paths (external APIs, legacy code) harder to test; requires strategic mocking

### Mitigation
- Provide test examples and templates for common patterns (commands, controllers, services)
- Code reviews verify TDD compliance and test quality
- Incremental adoption: apply TDD to new code and refactoring; gradually increase test coverage

## Related Decisions

- ADR 0007: SOLID Principles (complement TDD with testable architecture)
- ADR 0003: Conventional Commits (document test-related changes: `test:`, `feat:`)
- ADR 0004: Runtime and database platform (stable platform for reliable testing)

## Links

- PHPUnit Documentation: https://phpunit.de/
- TDD Best Practices: https://martinfowler.com/bliki/TestDrivenDevelopment.html
- Growing Object-Oriented Software, Guided by Tests: https://www.amazon.com/Growing-Object-Oriented-Software-Guided-Tests/dp/0321503627
