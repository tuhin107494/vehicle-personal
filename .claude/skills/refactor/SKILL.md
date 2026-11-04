---
name: refactor
description: Restructure code in vehicle-management without changing behavior (extract a Service method, split a large controller or component, remove duplication, rename), protected by tests written first. Use when asked to clean up, simplify, split or reorganize code. Not for bug fixes (use /debug-issue) or new behavior (use /feature).
---

# Refactor

Rules: [architecture-solid](../../rules/architecture-solid.md), [coding-standards](../../rules/coding-standards.md), [context-understanding](../../rules/context-understanding.md), [testing](../../rules/testing.md).

## 1. Define the target
State in one line what changes in structure, and confirm that **no behavior changes**: same routes, request rules, response shapes, status codes, DB writes and UI output. If behavior must change, stop and use `/feature` or `/debug-issue`.

## 2. Pin the behavior
1. Find the tests that cover the code: `grep -rln "<route or class>" backend/tests`.
2. If coverage is missing for a path you will touch, add tests with `/write-tests` **before** refactoring, and make sure they pass on the current code.
3. For frontend-only refactors (no test runner), record the current behavior: the props, API calls and rendered states you must keep.

## 3. Refactor in small steps
1. One kind of change per step (extract, move or rename). After each step, run the covering tests with the targeted test run from `/verify`.
2. If a step breaks a test, revert that step's edit and try a smaller step, **at most 2 times**. Then stop and report.
3. Keep public contracts: route paths, Resource fields, the `lib/types.ts` shapes and component props used elsewhere. If a contract must change, stop and ask.
4. Don't mix in formatting-only changes to untouched code, or unrelated fixes.

## 4. Verify
Run `/verify`. If the refactor moved queries, validation or auth checks, run `/secure-check` first.

## Output checklist
- [ ] Structural change, in one line
- [ ] Tests that pinned the behavior (existing + added)
- [ ] Steps taken
- [ ] Contracts unchanged (confirmed)
- [ ] `/verify` table
