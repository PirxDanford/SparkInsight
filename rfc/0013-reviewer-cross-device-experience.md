# RFC 0013: Reviewer Cross-Device Experience

Status: Draft

Date: 2026-07-05

## Summary

Define and deliver the reviewer and reader cross-device experience for tablet and mobile while keeping the initial scope desktop-first.

## Problem

The current reviewer flow is optimized for desktop and should remain the initial release focus. Without a dedicated RFC, mobile and tablet requirements risk being mixed into release-critical work.

## Proposal

1. Treat desktop reviewer UX as the initial baseline; do not block the initial release on tablet/mobile parity.
2. Define responsive breakpoints and layout behavior for reviewer dashboard and reader item views on tablet and mobile.
3. Specify touch-first interactions for selection, panel toggling, queue navigation, and note editing.
4. Ensure reviewer context and preferences remain stable across device classes (filters, sort, panel mode, queue return context where applicable).
5. Run a formal cross-device acceptance pass and capture gaps, regressions, and follow-up actions in tracked checklist items.

## Out Of Scope

- Replacing desktop-focused initial acceptance criteria.
- Major visual redesign unrelated to responsive behavior.

## Deliverables

- Cross-device UX specification for reviewer dashboard and reader item interactions.
- Implemented responsive updates for tablet and mobile reviewer flows.
- Acceptance checklist results for desktop, tablet, and mobile with documented residual gaps.
- Updated documentation for responsive behavior and supported usage expectations.

## Motivation

Separating cross-device work preserves the initial release focus while still making tablet/mobile support a concrete, tracked commitment.

## Links

- RFC 0008: Initial Reviewer Display Interface
- RFC ROADMAP: Cross-device backlog and Chapter 3 follow-up items
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles
