# RFC 0007: Data Importing Method for Content Ingestion

Status: Implemented

Date: 2026-04-18

## Summary

Define a method for importing content data (primarily from Scrivener project backups) into the review platform.

## Problem

Authors need to provide content for review without manual reformatting. The system must handle structured backup imports, validate project data, and store versions immutably in MariaDB.

## Proposal

1. Support Scrivener backup directory import (`.scriv`) via CLI as the primary ingestion path.
2. Parse and validate `.scrivx` hierarchy metadata and `Files/Data/<UUID>/content.rtf` content.
3. Store as immutable versions with metadata (author, timestamp, hierarchy/order metadata).
4. Provide feedback on import success/failures and safe purge/re-import operations.
5. Retain FDX directory import as a compatibility fallback, not the preferred path.

For local testing:
- Set up local MariaDB instance (via Docker or XAMPP).
- Create test import scripts and sample data files.
- Verify parsing, storage, and version tracking without external sync.

## Motivation

Enables seamless content ingestion for the review workflow.

## Open Questions

- Should web upload support be added for backup archives/directories?
- Error handling for malformed imports?
- Batch import capabilities?

## Links

- PHP XML Parsing: https://www.php.net/manual/en/book.xml.php
- MariaDB: https://mariadb.org/
- RFC 0011: Quality-First Development Framework (implementation requires comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (import validation, error handling)
- ADR 0007: Apply SOLID Principles (parser, validator, storage as separate concerns)

## Implementation Notes

- Implementation present in `src/Service/ContentImportService.php` and `src/Command/ScrivenerImportCommand.php` which provide backup parsing/import, directory-aware metadata handling, and CLI import/dry-run support.
- Database support for content versions (table `content_versions`) is part of the baseline schema in `database/migrations/001_initial_schema.sql`; the initial development history is retained as dev-only snapshots (for example, `database/migrations/dev_only_add_content_versions_and_reviews.sql`).
- Unit tests covering validation, import hierarchy/order handling, and rollback exist in `tests/Unit/Service/ContentImportServiceTest.php`.
