# ADR 0007: Apply SOLID Principles to Code Architecture

Status: Accepted

Date: 2026-05-20

## Context

SparkInsight is evolving to handle multiple user roles (authors, reviewers, admins), OAuth providers (GitHub, Google), and complex workflows (invitation management, feedback lifecycle, data importing). Current code shows increasing complexity with large classes, tight coupling between components, and difficulty testing isolated functionality. Without a guiding architecture, future changes risk cascading failures and make code difficult to understand.

## Decision

Apply SOLID Principles as the architectural foundation for all project code.

### SOLID Principles

1. **Single Responsibility Principle (SRP)**
   - Each class has one reason to change
   - Example: `UserService` handles user CRUD; `AuthController` handles HTTP routing; `OAuthProviderFactory` creates OAuth providers
   - Benefit: Easy to test, modify, and reuse

2. **Open/Closed Principle (OCP)**
   - Code should be open for extension, closed for modification
   - Example: Use factory patterns for OAuth providers; new providers added without changing existing code
   - Benefit: Safe to extend without breaking existing functionality

3. **Liskov Substitution Principle (LSP)**
   - Derived classes must be substitutable for their base classes
   - Example: All OAuth providers implement consistent interface for `getAuthorizeUrl()`, `getAccessTokenUrl()`, etc.
   - Benefit: Polymorphism works correctly; code doesn't need special cases for subtypes

4. **Interface Segregation Principle (ISP)**
   - Clients should not depend on interfaces they don't use
   - Example: Don't create monolithic `UserManager` interface; separate `UserAuthenticator`, `UserCreator`, `UserRepository`
   - Benefit: Classes only import what they need; easier to mock in tests

5. **Dependency Inversion Principle (DIP)**
   - Depend on abstractions, not concrete implementations
   - Example: Inject `OAuthProviderFactory` into `AuthController` rather than creating it inside
   - Benefit: Loose coupling; testability; easy to swap implementations (mocks, alternative providers)

### Application Guidelines

- Inject dependencies via constructor parameters (dependency injection)
- Use interfaces and abstract classes to define contracts
- Keep classes focused and cohesive
- Avoid global state and static methods where possible
- Use composition over inheritance

## Consequences

### Positive
- Code is more modular, flexible, and easier to understand
- Changes localized to responsible classes; lower risk of side effects
- Natural support for testing through dependency injection
- Easier to onboard new team members; clear separation of concerns
- Future features (new providers, new roles) integrate without breaking existing code

### Negative
- Requires more upfront design thinking (worth the investment)
- More files/classes initially (offset by clarity and maintainability)
- Team must understand and enforce SOLID principles during code review

### Mitigation
- Provide code examples showing SOLID in action (in codebase and documentation)
- Code reviews explicitly check for SRP violations, tight coupling, and injection patterns
- Refactor existing code incrementally when it violates SOLID

## Related Decisions

- ADR 0006: Test-Driven Development (TDD naturally produces SOLID code)
- ADR 0003: Conventional Commits (document architectural changes: `refactor:`)
- RFC 0011: Quality-First Development Framework (unified approach to TDD + SOLID)

## Links

- SOLID Principles Overview: https://en.wikipedia.org/wiki/SOLID
- Uncle Bob (Robert C. Martin) - The SOLID Principles: https://blog.cleancoder.com/
- Dependency Injection in PHP: https://www.phptherightway.com/
- Martin Fowler - Dependency Injection: https://martinfowler.com/articles/injection.html
