# 1. Record architecture decisions

- Status: Accepted
- Date: 2026-11-04

## Context

Vehicle Management has two apps (a Laravel API and a Next.js dashboard), several developers and AI coding agents working on it. Decisions such as "no repository layer", "money in integer paisa" and "shop isolation through a global scope" exist in the code but are written down nowhere. Without a record, new contributors and agents reopen them or work around them.

## Decision

We record every significant architecture decision as an Architecture Decision Record (ADR) in `docs/adr/`.

- **File name:** `NNNN-kebab-case-title.md`, numbered in sequence and never reused.
- **Sections:** Status (Proposed, Accepted, Deprecated, or Superseded by NNNN), Date, Context, Decision, Consequences.
- **What needs an ADR:** a new layer or pattern, a new runtime dependency, a change to tenancy, auth, money handling, transaction isolation, API versioning or the error envelope, and any decision the rules in `.claude/rules/` call "decision pending" once it is made.
- An accepted ADR is not edited later. To change a decision, write a new ADR that supersedes it, and update the old one's Status line only.
- The PR that implements a decision includes its ADR.

## Consequences

- The reasons behind the architecture can be found in one place, so reviews can point to a decision instead of re-arguing it.
- `.claude/rules/architecture-solid.md` requires an ADR before adding a layer, package or cross-cutting pattern.
- Writing a short document adds a small cost to each such change.
