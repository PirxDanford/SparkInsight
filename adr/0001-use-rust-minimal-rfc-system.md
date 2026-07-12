# ADR 0001: Use a Rust-based minimal ADR system

Status: Proposed

Date: 2026-04-15

## Context

We need a lightweight architecture decision record process for the Feedbacksystem project.
The ADR system should be simple, Markdown-first, and easy to adopt without introducing heavy tooling.

The project is new, and we want to keep decisions visible in the repository while supporting future RFC-style discussion.

## Decision

Adopt the minimal Rust ADR tool [joshrotenberg/adrs](https://github.com/joshrotenberg/adrs).

This repository is a small Rust-based CLI for managing Architectural Decision Records and matches our preference for a minimal, Rust-native approach.

## Consequences

- ADRs remain simple Markdown files in `adr/`.
- We can extend documentation and automation later using the Rust toolchain if desired.
- We avoid introducing heavier ADR ecosystems until the project maturity justifies it.

## Links

- Repository: https://github.com/joshrotenberg/adrs
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles
