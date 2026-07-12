# RFC 0013: Reviewer Cross-Device Experience (v1.1)

Status: Draft

Date: 2026-07-05

## Summary

Define and deliver the reviewer and reader cross-device experience for tablet and mobile in v1.1, while keeping v1.0.0 desktop-first.

## Problem

The current reviewer flow is optimized for desktop and should remain the v1.0.0 release focus. Without a dedicated RFC, mobile and tablet requirements risk being mixed into release-critical v1 work.

## Proposal

1. Treat desktop reviewer UX as the v1.0.0 baseline; do not block v1.0.0 on tablet/mobile parity.
2. Define responsive breakpoints and layout behavior for reviewer dashboard and reader item views on tablet and mobile.
3. Specify touch-first interactions for selection, panel toggling, queue navigation, and note editing.
4. Ensure reviewer context and preferences remain stable across device classes (filters, sort, panel mode, queue return context where applicable).
5. Run a formal cross-device acceptance pass and capture gaps, regressions, and follow-up actions in tracked checklist items.

## Out Of Scope

- Replacing desktop-focused v1.0.0 acceptance criteria.
- Major visual redesign unrelated to responsive behavior.

## Deliverables

- Cross-device UX specification for reviewer dashboard and reader item interactions.
- Implemented responsive updates for tablet and mobile reviewer flows.
- Acceptance checklist results for desktop, tablet, and mobile with documented residual gaps.
- Updated documentation for responsive behavior and supported usage expectations.

## Motivation

Separating cross-device work into v1.1 preserves v1.0.0 release focus while still making tablet/mobile support a concrete, tracked commitment.

## Links

- RFC 0008: Initial Reviewer Display Interface
- RFC ROADMAP: v1.1 backlog and Chapter 3 follow-up items
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles
