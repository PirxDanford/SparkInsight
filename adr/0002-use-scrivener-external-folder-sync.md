# ADR 0002: Use Scrivener External Folder Sync for Export

Status: Accepted

Date: 2026-04-18

## Context

The feedback system requires a reliable way to export Scrivener content for review ingestion. Authors must stay within Scrivener's workflow, and exports need to be automated or low-effort to ensure adoption. Manual exports are error-prone and disrupt writing flow.

We evaluated multiple export approaches in RFC 0003, including direct package reading, custom compile formats, and scripting. The PoC validated FDX as a parseable format, but we need a sustainable export mechanism.

## Decision

Rely on Scrivener's built-in "Sync -> with External Folder" feature for exporting content to the review system.

- Configure Scrivener to sync the "For Review" collection to a dedicated folder in the project repository (e.g., `scrivener/`).
- Use FDX format for structured, XML-based exports that support programmatic parsing.
- Trigger syncs manually or via Scrivener's auto-sync on changes, depending on author preference.

This approach leverages Scrivener's native functionality, minimizing custom tooling and ensuring compatibility across platforms.

## Consequences

- **Positive**: Native integration reduces setup complexity; sync is reliable and preserves Scrivener's version control; FDX provides structured data for parsing and anchoring.
- **Negative**: Requires authors to configure sync settings; sync may include unwanted files if not scoped properly; dependent on Scrivener's feature stability.
- **Mitigation**: Document setup steps in RFC 0004; validate exports programmatically; monitor for Scrivener updates affecting sync.

## Links

- Scrivener documentation: https://www.literatureandlatte.com/scrivener/user-manual/18.0_syncing
- RFC 0003: Scrivener export proof of concept
- ADR 0006: Adopt Test-Driven Development (implement sync validation with TDD)
- ADR 0007: Apply SOLID Principles (sync component design)