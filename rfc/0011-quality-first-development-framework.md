# RFC 0011: Quality-First Development Framework

Status: Implemented

Date: 2026-05-20

Priority: **CRITICAL - Phase 0 (Foundational)**

## Summary

Establish a comprehensive Quality-First Development Framework that integrates Test-Driven Development (TDD), SOLID Principles, and continuous quality gates. This framework ensures all future work—features, bug fixes, refactoring—follows consistent standards that prioritize reliability, maintainability, and testability.

## Problem

Previous development cycles revealed significant gaps:
- Code coverage dropped to 72.62% with large uncovered sections in critical paths (OAuth, user management)
- Complex business logic lacked proper test coverage, making refactoring risky
- Inconsistent code organization and architectural decisions led to tight coupling and difficult testing
- New features introduced regressions in areas that should have been stable
- Team lacked shared standards for code quality expectations

## Proposal

### 1. Test-Driven Development (ADR 0006)

All new features and bug fixes must follow TDD:
- Write failing tests first
- Implement minimal code to pass tests
- Refactor to improve quality while keeping tests green

**Targets:**
- 100% code coverage on project code (excluding vendor libraries)
- All critical paths (authentication, user management, data import) verified by tests
- Both happy paths and error scenarios covered

### 2. SOLID Principles (ADR 0007)

Architecture must follow SOLID principles:
- **Single Responsibility**: Each class has one reason to change
- **Open/Closed**: Extend without modifying existing code
- **Liskov Substitution**: Derived classes are substitutable for base classes
- **Interface Segregation**: Don't depend on interfaces you don't use
- **Dependency Inversion**: Depend on abstractions, not concrete implementations

**Benefits:**
- Modular, testable code naturally emerges
- Easy to understand and modify
- Safe to refactor and extend
- Team onboarding is smoother

### 3. Quality Gates in CI/CD

Implement automated quality checks:
- **Test Execution**: All tests must pass before merge
- **Coverage Verification**: Code coverage must not decrease
- **Static Analysis**: PHPStan/Psalm for type checking and potential bugs
- **Code Style**: PSR-12 compliance via PHP-CS-Fixer
- **Linting**: Identify unused variables, unreachable code, etc.

### 4. Code Review Standards

All PRs must include:
- Test coverage for new functionality
- No architectural violations (SOLID principles)
- Clear commit messages (ADR 0003: Conventional Commits)
- Updated documentation if behavior changes

## Implementation Phases

### Phase 0 (Now): Foundation
- Document TDD and SOLID in ADRs (ADR 0006, ADR 0007) ✅
- Update ROADMAP to prioritize this RFC ✅
- Create test templates and examples
- Define CI/CD quality gate requirements and hand off implementation to Phase 5

### Phase 1: Enforcement
- Require 100% coverage for new features
- Code reviews enforce SOLID principles
- Team training on TDD and SOLID patterns
- Gradual refactor of existing code to meet standards

### Phase 2: Optimization
- Measure and reduce flaky tests
- Optimize slow tests
- Establish coverage trends and improvements
- Document patterns discovered during implementation

## Success Criteria

- [ ] All new code has 100% coverage
- [ ] Code review checklist includes SOLID verification
- [ ] Zero regressions in critical paths (OAuth, user management)
- [ ] Team completes TDD/SOLID training
- [ ] Coverage reports show consistent improvement
- [ ] CI/CD pipeline rejects PRs that decrease coverage

## Motivation

SparkInsight handles critical feedback workflows for manuscript review. Authors and reviewers depend on the system working reliably. Quality-First Development ensures:
- **Trust**: Features work as intended; bugs are caught early
- **Maintainability**: Future changes are safe; code is easy to understand
- **Velocity**: Less time debugging; faster, more confident development
- **Team Scalability**: New contributors can understand and extend code confidently

## Open Questions

- Should existing code be incrementally refactored to meet standards, or only new code?
  - **Answer**: New code first; refactor existing code opportunistically during bug fixes
- How strict should code review enforcement be during transition?
  - **Answer**: Educate first (Phase 1), enforce strictly from Phase 2 onward
- Which static analysis tools should we use (PHPStan, Psalm, both)?
  - **Answer**: Start with PHPStan for type checking; add Psalm later if needed

## Related Decisions

- ADR 0006: Adopt Test-Driven Development (TDD)
- ADR 0007: Apply SOLID Principles
- ADR 0003: Conventional Commits and Semantic Versioning
- ADR 0004: PHP 8.5 & MariaDB
- ADR 0005: Slim and League OAuth2 Client

## Links

- TDD Best Practices: https://martinfowler.com/bliki/TestDrivenDevelopment.html
- SOLID Principles: https://en.wikipedia.org/wiki/SOLID
- PHPUnit Documentation: https://phpunit.de/
- The Three Rules of TDD: https://blog.cleancoder.com/uncle-bob/2014/12/17/TheCycles.html
