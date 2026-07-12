# RFC 0003: Scrivener ingestion proof of concept for review import

Status: Implemented

Date: 2026-04-15

## Summary

Define a proof-of-concept for ingesting Scrivener data into a review-ready format. The goal is to establish a repeatable path that keeps author workflows in Scrivener while enabling review import, anchoring, and change tracking.

## Problem

The review platform needs structured content from Scrivener, and reviewers require consistent hierarchy/order semantics. Authors must stay in Scrivener, and the ingestion source must preserve binder relationships, document identifiers, and version context.

## Proposal

Build a PoC that evaluates how Scrivener data can be ingested into the review system. The PoC should cover:

1. Source trigger and delivery
  - Candidate inputs include compile/export artifacts and Scrivener project backups.
  - The PoC should compare manual export workflows with direct project/backup ingestion.

2. Input format
   - Use an intermediate format that preserves content structure and enough metadata for review anchoring.
   - Candidate PoC formats: structured XML, Markdown with metadata frontmatter, or JSON.

3. Content and metadata extraction
   - Include document hierarchy, section titles, text bodies, and Scrivener-specific metadata such as document IDs, labels, and revision numbers.
   - Preserve Scrivener document order and section boundaries to support review anchoring at paragraph or block granularity.

4. Ingestion assumptions
   - The review import step should map exported material to immutable review versions.
   - The PoC should validate that exported data can be used to detect changed regions and attach review comments reliably.

## Alternative approaches

### Approach A: Use Scrivener project backup/package ingestion

- Ingest by reading `.scriv` backup contents directly.
- Extract `.scrivx` and `Files/Data/<UUID>/content.rtf` to reconstruct hierarchy and IDs.
- Pros:
  - Access to native document identifiers and structure.
  - Can support more fine-grained anchoring and metadata.
- Cons:
  - Scrivener project format is not a published API and may change.
  - Platform-specific behavior on macOS and Windows packages.

### Approach B: Use Scrivener compile/export with a custom format

- Configure a custom compile format inside Scrivener that emits XML/JSON or Markdown with embedded metadata.
- Pros:
  - Uses supported Scrivener export path.
  - Easier for authors to adopt as a normal export operation.
- Cons:
  - May require author setup and maintenance of compile presets.
  - Potential loss of native IDs unless explicitly embedded.

### Approach C: Use Scrivener automation or scripting

- Leverage Scrivener AppleScript (macOS) or Windows automation to drive exports and capture project metadata.
- Pros:
  - Can automate exports from the author environment.
  - Can potentially gather rich metadata beyond compiled text.
- Cons:
  - Platform-dependent and more complex to maintain.
  - Requires author permission and environment configuration.

### Approach D: Use a plain text / Markdown export plus heuristic anchoring

- Export Scrivener content to Markdown or plain text and infer structure during import.

## Quality Considerations

Implementation of this PoC must comply with:
- **RFC 0011**: Quality-First Development Framework (foundational requirement)
- **ADR 0006**: Adopt Test-Driven Development (test export parsing and format validation)
- **ADR 0007**: Apply SOLID Principles (separate export mechanism, format parsing, and validation)
- Pros:
  - Minimal Scrivener-specific tooling.
  - Simple to parse and review.
- Cons:
  - Loses Scrivener-native IDs and may make change detection less accurate.
  - Anchoring becomes heuristic rather than deterministic.

## PoC scope

The PoC should verify at least one ingestion path and compare it with one alternative:

- Path 1: Extract structure/content from `.scriv` backup data (`.scrivx` + `Files/Data`).
- Path 2: Export via compile to Markdown/HTML and validate review import using heuristic mapping.

The PoC should produce example exports and a short import prototype that demonstrates:

- importing Scrivener project content into the review system
- preserving document structure and identifiers
- detecting changed regions between two exported versions
- anchoring review comments to target sections

## Success criteria

The PoC is successful when it can:

- ingest Scrivener project data into the review platform without manual content rekeying
- preserve enough structure to support reviewer comments on discrete sections
- show at least one feasible ingestion workflow that is compatible with author Scrivener usage

## Outcome

The PoC outcome favored direct Scrivener backup/project ingestion over export-centric paths for production use.

- Selected path: parse `.scrivx` binder structure plus `Files/Data/<UUID>/content.rtf` content.
- Rejected as primary path: FDX/DOCX-only exports, due to insufficient relationship data for full binder reconstruction.
- Operational result: importer now preserves hierarchy/order metadata and supports directory-aware reviewer navigation.

## Open questions

- Should the review system depend on a Scrivener-specific export path, or should it support generic document exports only?
- What level of granularity is required for review anchoring: document, section, paragraph, or line?
- Is preserving Scrivener internal IDs essential, or is a stable generated anchor enough?
- Should the PoC include a lightweight export assistant for authors, or focus on backend ingestion only?
