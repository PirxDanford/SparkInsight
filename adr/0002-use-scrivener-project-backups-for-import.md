# ADR 0002: Use Scrivener Project Backups for Import

Status: Accepted

Date: 2026-07-04

## Context

The feedback system requires a reliable way to ingest Scrivener content while preserving binder hierarchy, item ordering, and stable identifiers. Authors must stay within Scrivener's workflow, and the import source must support deterministic reconstruction of directories and text nodes.

Initial export-oriented approaches (FDX/DOCX/external folder sync) did not preserve enough relationship data for robust hierarchy reconstruction. Evaluation in RFC 0003 confirmed the Scrivener project backup (`.scriv`) is the most complete source because it contains both binder metadata (`.scrivx`) and item content (`Files/Data/<UUID>/content.rtf`).

## Decision

Rely on Scrivener project backups (`.scriv`) as the primary import source for the review system.

- Read binder hierarchy and ordering from `.scrivx`.
- Read item content from `Files/Data/<UUID>/content.rtf`.
- Import both directory and text nodes, preserving binder order via stored metadata (`order_path`, `list_path`).
- Keep FDX directory import as legacy fallback only.

This approach provides complete structural fidelity required by reviewer navigation and queue rendering.

## Consequences

- **Positive**: Preserves true binder hierarchy and ordering; supports deterministic IDs/paths for review anchoring; enables consistent directory display in reviewer queues.
- **Negative**: Requires access to backup/project package; backup structure is Scrivener-specific and not a formal public API.
- **Mitigation**: Validate project structure at import time; keep importer parser modular and tested; retain fallback import mode for compatibility.

## Links

- Scrivener documentation: https://www.literatureandlatte.com/scrivener/user-manual
- RFC 0003: Scrivener ingestion proof of concept
- ADR 0006: Adopt Test-Driven Development (implement sync validation with TDD)
- ADR 0007: Apply SOLID Principles (import parser/service component design)