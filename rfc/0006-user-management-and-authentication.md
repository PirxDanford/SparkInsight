# RFC 0006: User Management and Authentication via External Providers

Status: Implemented

Date: 2026-04-18

## Summary

Implement invitation-based user onboarding and authentication using external identity providers. Phase 1 supports GitHub and Google as the initial provider set, with invitations controlling role assignment for authors and reviewers.

## Problem

The platform needs secure user authentication for authors and reviewers without managing passwords or accounts locally. Users should log in via trusted platforms like LinkedIn, OpenID, Google, Facebook, etc., to simplify onboarding and enhance security.

## Proposal

1. Integrate OAuth 2.0 / OpenID Connect with providers: Google, Facebook, LinkedIn, and generic OpenID.
2. Use a PHP library like `league/oauth2-client` for handling authentication flows.
3. Store minimal user data (e.g., provider ID, email, name) in MariaDB upon first login.
4. Assign roles (author, reviewer) based on initial setup or invitation.
5. Ensure logout and session management.

For local testing:
- Use a local PHP server (e.g., built-in or XAMPP).
- Mock external providers with tools like `oauth2-mock-server` or configure test apps on providers.
- Test with dummy accounts and verify role assignment.

## Motivation

Reduces security risks, leverages existing user ecosystems, and simplifies user experience.

## Open Questions

- Which providers to prioritize (e.g., start with Google and OpenID)?
- How to handle role assignment for new users?
- Session timeout and refresh token management?

## Links

- OAuth 2.0: https://oauth.net/2/
- League OAuth2 Client: https://oauth2-client.thephpleague.com/
- RFC 0011: Quality-First Development Framework (implementation requires comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (OAuth flow testing, provider integration)
- ADR 0007: Apply SOLID Principles (provider factory pattern, dependency injection)