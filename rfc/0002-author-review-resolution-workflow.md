# RFC 0002: Author-driven review resolution workflow

Status: Accepted

Date: 2026-04-15

## Summary

Define how author changes are surfaced to reviewers and how review items are resolved.

## Problem

The author should not have feedback automatically closed by the platform.
Instead, the author must decide whether a change resolves or preserves reviewer feedback.

## Proposal

1. New Scrivener document versions are analyzed for change impact.
2. Reviews attached to changed regions are marked as `needs_author_review`.
3. The author receives a dashboard view showing:
   - review text
   - original target content
   - changed current content
   - version numbers involved
4. The author selects one of:
   - `Resolved` — feedback is addressed by the change.
   - `Still relevant` — feedback still applies despite the change.
   - `Ignored` — feedback was intentionally not incorporated.
5. Reviewers see status updates of their own feedback only.
6. Reviewer history surfaces author-attributed resolution metadata for resolved items (resolver identity/role, decision outcome, and resolution timestamp), so "resolved by author" is explicit and auditable.

## Motivation

This keeps the decision explicit and avoids incorrect automatic closure.
Authors stay in control, and reviewers retain trust in the system.

## Notes

- The platform should preserve an immutable audit trail of all author review decisions.
- The author view can display a per-review timeline of version changes.
- The system should support later extension to optional reviewer comments on author responses.

## Quality Considerations

Implementation of this RFC must comply with:
- **RFC 0011**: Quality-First Development Framework (foundational requirement)
- **ADR 0006**: Adopt Test-Driven Development (test workflow state transitions and author decisions)
- **ADR 0007**: Apply SOLID Principles (audit trail, decision recording, and status updates as separate concerns)
