# ADR 0008: CLI-First Operations with Admin UI Fallback

Status: Accepted

Date: 2026-07-25

## Context

SparkInsight maintenance and routine operations (for example migrations, one-time data corrections, operational checks, and batch tasks) should be reliable, automatable, and reproducible across environments.

In some deployments, shell access may be unavailable or intentionally restricted. In those environments, administrators still need a safe and supported way to run essential maintenance workflows.

Without a clear operating principle, maintenance behavior can diverge between command-line and web interfaces, increasing risk and support complexity.

## Decision

Adopt a **CLI-first** operational model with **Admin UI fallback** support.

### Operational Order

1. Every maintenance or routine task is defined and implemented for the CLI first.
2. If shell access is unavailable, constrained, or unsuitable for the target environment, provide equivalent support in the Admin interface.
3. CLI and Admin UI paths must share the same domain/service-layer behavior to avoid semantic drift.

### Implementation Rules

- CLI remains the canonical execution surface for maintenance workflows.
- Admin UI fallback is required for tasks that are operationally necessary in environments without CLI access.
- Validation, authorization, audit logging, and side-effect behavior must be equivalent across CLI and Admin UI entry points.
- High-risk operations must require explicit confirmations and clear operator intent in both surfaces.
- Where feasible, task logic should live in reusable services, with thin adapters for CLI commands and Admin controllers.

## Consequences

### Positive

- Strong automation and scriptability for maintenance and release processes.
- More predictable operations due to one canonical behavior definition.
- Better support for constrained hosting environments via Admin UI fallback.
- Lower long-term maintenance risk by reducing duplicate business logic.

### Negative

- Some tasks require two delivery surfaces (CLI and Admin UI), increasing implementation scope.
- Additional UX work is needed to make fallback workflows safe and understandable.
- More acceptance testing is needed to verify parity between CLI and Admin behavior.

### Mitigation

- Keep core task behavior in shared services and test those services first.
- Add parity-oriented tests for critical operations exposed through both surfaces.
- Use explicit capability checks and clear audit entries for all maintenance executions.

## Related Decisions

- ADR 0006: Adopt Test-Driven Development
- ADR 0007: Apply SOLID Principles to Code Architecture
- RFC 0011: Quality-First Development Framework

## Links

- Roadmap backlog item: Admin push-button maintenance interface (`rfc/ROADMAP.md`)