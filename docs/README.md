# Documentation

Structured project documentation for the isbot quiz bot. Split by intent:

| Doc | Answers | Audience |
|---|---|---|
| [requirements.md](requirements.md) | **What** the system must do (functional + non-functional), as-built and planned | Product / professor / researcher |
| [design.md](design.md) | **Why** key cross-cutting decisions were made (ADR log) | Maintainers |
| [features/](features/) | Per-feature specs: requirements + design for one feature, traced to the SRS | Whoever builds the feature |
| [../ARCHITECTURE.md](../ARCHITECTURE.md) | **How** the system is built right now (living reference: schema, flow, subsystems) | Maintainers |
| [../ROADMAP.md](../ROADMAP.md) | Backlog & future ideas | Everyone |

## Conventions

- **Requirement IDs.** Functional `FR-<area>-<n>`, non-functional `NFR-<n>`.
  Feature specs introduce their own `FR-<FEATURE>-<n>` IDs and note which
  system requirements they extend or supersede. IDs are stable — don't renumber.
- **Status.** Each requirement is tagged `built` / `planned` / `proposed`.
- **Hebrew.** All user-facing strings are Hebrew/RTL (see [NFR-1](requirements.md)).
  Docs themselves are in English; quoted UI strings stay in Hebrew.
- **Source of truth.** `ARCHITECTURE.md` describes what *is*; `requirements.md`
  describes what *should be*. When a feature ships, fold its design into
  `ARCHITECTURE.md` and flip its requirements to `built`.

## Active feature specs

- [features/cohorts.md](features/cohorts.md) — multi-group support, per-group
  week of progress, mandatory group onboarding (in design, not yet built).
