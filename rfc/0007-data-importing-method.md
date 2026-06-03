# RFC 0007: Data Importing Method for Content Ingestion

Status: Draft

Date: 2026-04-18

## Summary

Define a method for importing content data (e.g., from Scrivener exports) into the review platform.

## Problem

Authors need to upload or sync content for review without manual processes. The system must handle structured data imports, validate formats, and store versions immutably in MariaDB.

## Proposal

1. Support file upload (e.g., FDX, Markdown) via web interface.
2. Parse and validate imported data using PHP (e.g., XML parser for FDX).
3. Store as immutable versions with metadata (author, timestamp).
4. Provide feedback on import success/failures.
5. Integrate with ADR 0002 for Scrivener sync as a future extension.

For local testing:
- Set up local MariaDB instance (via Docker or XAMPP).
- Create test import scripts and sample data files.
- Verify parsing, storage, and version tracking without external sync.

## Motivation

Enables seamless content ingestion for the review workflow.

## Open Questions

- Supported formats beyond FDX?
- Error handling for malformed imports?
- Batch import capabilities?

## Links

- PHP XML Parsing: https://www.php.net/manual/en/book.xml.php
- MariaDB: https://mariadb.org/
- RFC 0011: Quality-First Development Framework (implementation requires comprehensive tests)
- ADR 0006: Adopt Test-Driven Development (import validation, error handling)
- ADR 0007: Apply SOLID Principles (parser, validator, storage as separate concerns)