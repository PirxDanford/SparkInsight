# RFC 0005: Contribution Documentation

Status: Implemented

Date: 2026-04-18

## Summary

Establish comprehensive contribution documentation to guide new and existing contributors on how to participate in the project effectively.

## Problem

Without clear contribution guidelines, contributors may struggle with setup, coding standards, and project processes, leading to inconsistent code, wasted time, and frustration. This is especially important as the project grows and adopts practices like conventional commits.

## Proposal

Create a `CONTRIBUTING.md` file in the repository root with the following sections:

1. **Getting Started**
   - Prerequisites (e.g., development environment, tools)
   - Cloning and setting up the project

2. **Development Setup**
   - Installation instructions
   - Running the project locally
   - Environment configuration

3. **Coding Guidelines**
   - Code style (language-specific if decided)
   - Naming conventions
   - Best practices

4. **Commit Conventions**
   - Adopt Conventional Commits as per ADR 0003
   - Examples and rationale

5. **Testing**
   - How to run tests
   - Writing new tests
   - Coverage expectations
   - Document `composer coverage` and `composer coverage-html` for running coverage reports locally
   - Reference RFC 0011 (Quality-First Framework) and ADR 0006 (TDD requirements)

6. **Code Architecture**
   - Reference ADR 0007 (SOLID Principles) and its application to the codebase
   - Dependency injection patterns for testability
   - Separating concerns and responsibilities
   - Creating issues and feature requests
   - Pull request process
   - Code review guidelines

7. **Community and Communication**
   - Where to ask questions
   - Code of conduct (if applicable)

## Motivation

Clear documentation lowers the barrier to entry, ensures consistency, and fosters a welcoming community. It ties into our ADR on conventional commits by providing practical guidance.

## Open Questions

- Should we include a code of conduct?
- What level of detail for setup instructions before language/framework decisions?
- How to handle contributions from non-technical users (e.g., documentation-only)?

## Links

- ADR 0003: Adopt Conventional Commits and Semantic Versioning
- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles
- RFC 0011: Quality-First Development Framework

## Implementation

Completed 2026-07-12.

- Delivered `CONTRIBUTING.md` at repository root.
- Included setup, coding, commit, and pull request guidance.
- Included explicit TDD and SOLID requirements and references.
- Included test and coverage command guidance for local contributor workflows.