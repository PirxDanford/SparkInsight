# ADR 0005: Use Slim 4 and league/oauth2-client for OAuth-based login

Date: 2026-04-19

## Decision

Use Slim 4 as the primary PHP application framework and `league/oauth2-client` for OAuth authentication.

## Context

The project must support a lightweight PHP web frontend, be installable and updateable via Composer, and run in a local development environment without requiring database setup.

## Options considered

- Symfony full-stack: powerful but heavier than needed for an MVP.
- Laravel: too opinionated for a small review workflow prototype.
- Slim 4: lightweight, PSR-7 compatible, and easy to install with Composer.
- Custom bare PHP routing: possible, but harder to maintain and less consistent with ecosystem best practices.

## Decision details

The stack chosen is:

- `slim/slim` for HTTP routing and middleware
- `slim/psr7` for PSR-7 request/response objects
- `slim/php-view` for lightweight template rendering
- `league/oauth2-client` for OAuth provider integration
- `vlucas/phpdotenv` for environment configuration

OAuth provider support will start with GitHub and Google, because both providers support `http://localhost` redirect URIs and are easy to configure for local development. LinkedIn is planned later and intentionally omitted from the initial UI.

## Consequences

- The project is ready for Composer-based deployment and updates.
- The application structure will follow PSR-4 autoloading and modern PHP best practices.
- Local development can start without MySQL/MariaDB.
- Future provider additions remain easy to implement.

## Links

- Slim 4: https://www.slimframework.com/
- League OAuth2 Client: https://oauth2-client.thephpleague.com/
- PSR-7: https://www.php-fig.org/psr/psr-7/
- ADR 0006: Adopt Test-Driven Development (implement OAuth flows with comprehensive tests)
- ADR 0007: Apply SOLID Principles (dependency injection for providers and middleware)
- RFC 0011: Quality-First Development Framework
