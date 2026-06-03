# RFC 0008: Initial Reviewer Display Interface

Status: Draft

Date: 2026-04-18

## Summary

Develop the first version of the interface for reviewers to view content and submit feedback.

## Problem

Reviewers need a clean, functional UI to access assigned content, read it, and provide comments anchored to specific sections, without editing capabilities.

## Proposal

1. Build a PHP-based web interface with HTML/CSS/JS (minimal JS for interactivity).
2. Display content in a read-only viewer with section navigation.
3. Allow reviewers to add comments per paragraph/section, stored in MariaDB.
4. Show comment history and status (e.g., resolved by author).
5. Ensure responsive design for various devices.

For local testing:
- Run on local PHP server with sample content in database.
- Use browser dev tools to test UI; simulate reviewer logins.
- Validate comment submission and display without live deployment.

## Motivation

Provides the core reviewer experience to enable feedback collection.

## Open Questions

- UI framework (vanilla PHP templates or lightweight like Twig)?
- Comment anchoring granularity (paragraph vs. section)?
- Notification system for new content/reviews?

## Links

- PHP Templates: https://www.php.net/manual/en/tutorial.php
- Responsive CSS: https://developer.mozilla.org/en-US/docs/Learn/CSS/CSS_layout/Responsive_Design
- RFC 0011: Quality-First Development Framework (implementation must include comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (UI logic tested, comment submission verified)
- ADR 0007: Apply SOLID Principles (separate concerns: display, data access, user interaction)