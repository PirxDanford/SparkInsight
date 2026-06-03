# RFC 0004: Scrivener-side setup and workflow documentation

Status: Draft

Date: 2026-04-18

## Summary

Define the documentation and guidance for authors using Scrivener to prepare content for the review system. This includes setup steps, export configuration, and workflow best practices to ensure exported data is review-ready.

## Problem

Authors need clear, actionable guidance on configuring Scrivener for review exports. Without this, exports may lack necessary structure or metadata, breaking the review anchoring and change detection features.

## Proposal

Create comprehensive documentation covering:

1. Scrivener project setup for review workflows
   - Collection organization (e.g., "For Review" collection usage)
     - Note: In Scrivener, collections do not automatically include sub-items when adding a folder. Each individual document and folder must be explicitly added to the collection via the context menu (right-click > Add to Collection).
   - External folder sync configuration
   - Export format selection and rationale

2. Export workflow
   - Triggering exports (manual vs. automated)
   - File naming conventions
   - Version management in Scrivener

3. Best practices
   - Content structuring for optimal review anchoring
   - Metadata preservation tips
   - Troubleshooting common export issues

4. Integration points
   - How exports feed into the review system
   - Feedback on export quality and completeness

## Implementation notes

- Documentation should be hosted in the project repository (e.g., `docs/scrivener-setup.md`)
- Include screenshots or step-by-step guides where helpful
- Start with high-level setup and defer detailed steps until PoC validation
- Consider a quick-start checklist for new authors

## Open questions

- Should the documentation include platform-specific variations (macOS vs. Windows Scrivener)?
- What level of technical detail is appropriate for non-technical authors?
- Should there be automated validation of exported files before review import?

## Quality Considerations

Documentation must reference:
- **RFC 0011**: Quality-First Development Framework (explain how testing is part of review process)
- **ADR 0006**: Adopt Test-Driven Development (how authors can validate exports locally)
- **ADR 0007**: Apply SOLID Principles (structured approach to export configuration)