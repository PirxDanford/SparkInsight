# RFC 0008: Initial Reviewer Display Interface

Status: Implemented

Date: 2026-04-18

## Summary

Develop the first version of the interface for reviewers to view content and submit feedback.

## Problem

Reviewers need a clean, functional UI to access assigned content, read it, and provide comments anchored to specific sections, without editing capabilities.

## Proposal

1. Build a PHP-based web interface with HTML/CSS/JS (minimal JS for interactivity).
2. Display content in a read-only viewer with section navigation. This part is most important and needs the most attention, the reader should first and foremost have an undisturbed reading experience and the ability to comment needs to seamlessly blend in.
3. Limit the reviewer display to two reading states only: Pure Content and Content + Panel. The panel should be a visibility toggle, not a separate grid mode or additional view switch.
4. Allow reviewers to add comments in three ways, either for a whole item, by marking a position, or by marking a range of text, stored in MariaDB.
5. Improve folder display and navigation comfort in the reviewer queue with clearer hierarchy cues, child counts, and stronger expand/collapse behavior.
6. Keep queue context stable when opening an item so reviewers can return to the same filtered, sorted place without losing their position.
7. Remember the reviewer's preferred panel visibility so the default matches how they like to read.
8. Show comment history and status in reviewer history.
	- Explicit author-attributed resolution identity/decision semantics are defined in RFC 0002.
9. Ensure a high-quality desktop reviewer experience in the initial scope; cross-device behavior for tablet and mobile is deferred to RFC 0013.

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
- Should panel visibility default to hidden or visible for new reviewers?

## Links

- PHP Templates: https://www.php.net/manual/en/tutorial.php
- Responsive CSS: https://developer.mozilla.org/en-US/docs/Learn/CSS/CSS_layout/Responsive_Design
- RFC 0011: Quality-First Development Framework (implementation must include comprehensive tests)
- RFC 0002: Author-driven review resolution workflow (author-attributed resolution semantics)
- RFC 0013: Reviewer Cross-Device Experience
- ADR 0006: Adopt Test-Driven Development (UI logic tested, comment submission verified)
- ADR 0007: Apply SOLID Principles (separate concerns: display, data access, user interaction)