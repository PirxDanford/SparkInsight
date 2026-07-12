# RFC 0004: Scrivener-side setup and workflow documentation

Status: Implemented

Date: 2026-04-18

## Summary

Define the documentation and guidance for authors using Scrivener to prepare content for the review system. This includes setup steps, backup workflow configuration, and workflow best practices to ensure imported data is review-ready.

## Problem

Authors need clear, actionable guidance on producing and sharing Scrivener project backups for import. Without this, imports may lack necessary structure or metadata, breaking review anchoring, hierarchy display, and change detection features.

## Proposal

Create comprehensive documentation covering:

1. Scrivener project setup for review workflows
   - Binder organization and naming conventions for predictable review navigation.
   - Backup generation workflow (manual/automated backup settings).
   - Recommended backup location conventions within the repository.

2. Backup workflow
   - Triggering backups (manual vs. automated).
   - File naming conventions for backup snapshots.
   - Version management in Scrivener

3. Best practices
   - Content structuring for optimal review anchoring
   - Metadata preservation tips (`.scrivx` integrity, UUID stability)
   - Troubleshooting common backup/import issues

4. Integration points
   - How backups feed into the review system
   - Feedback on import quality and completeness

## Implementation notes

- Documentation should be hosted in the project repository (e.g., `docs/scrivener-setup.md`)
- Include screenshots or step-by-step guides where helpful
- Start with high-level setup and defer detailed steps until PoC validation
- Consider a quick-start checklist for new authors

## Open questions

- Should the documentation include platform-specific variations (macOS vs. Windows Scrivener)?
- What level of technical detail is appropriate for non-technical authors?
- Should there be automated validation of backup structure before review import?

## Quality Considerations

Documentation must reference:
- **RFC 0011**: Quality-First Development Framework (explain how testing is part of review process)
- **ADR 0006**: Adopt Test-Driven Development (how authors can validate imports locally)
- **ADR 0007**: Apply SOLID Principles (structured approach to import configuration)

## Implementation (v1.0.0)

Completed 2026-07-12.

- Delivered author-facing setup guide in `docs/scrivener-setup.md`.
- Added quick-start checklist, backup workflow steps, validation guidance, and troubleshooting.
- Documented platform notes (Windows/macOS workflow parity) and quality-framework alignment.