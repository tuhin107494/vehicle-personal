---
name: debug-issue
description: Find and fix the root cause of a bug in vehicle-management (wrong amounts, wrong status, 4xx/5xx errors, cross-shop data, UI not updating) with a regression test first. Use when behavior is wrong and the cause isn't known, or when given an error message, stack trace or bug report.
---

# Debug an issue

Rules: [context-understanding](../../rules/context-understanding.md), [testing](../../rules/testing.md), [error-handling-logging](../../rules/error-handling-logging.md). For money or state bugs, also [database-acid](../../rules/database-acid.md).

## 1. Restate
In 2–3 lines: what happens, what should happen, where (endpoint, screen, role, shop). If the expected behavior is unclear, **stop and ask**.

## 2. Reproduce
1. Find the path: route (`php artisan route:list --path=api`) → controller → FormRequest → Service → model, or page → `api()` call → endpoint.
2. Write a failing test with `/write-tests` (regression case) that shows the bug. Run it and keep the failing output.
3. If it can't be reproduced in a test after **2** attempts, stop. Report what you tried and what information you need (inputs, role, data). MUST NOT read `.env` or production data to reproduce.

## 3. Isolate
1. Form at most **3** hypotheses, ranked by likelihood, each with evidence from the code.
2. Check each one by reading code or narrowing the test, not by changing production code at random.
3. Name the root cause as `file:line` with one sentence on why it's wrong.

## 4. Fix
1. Make the smallest change that fixes the root cause, following the rules for that layer.
2. Run the regression test: it MUST pass now.
3. Grep for the same faulty pattern elsewhere. List other occurrences in the report. Fix them only if they're in scope.
4. If the fix fails after **2** attempts, stop and report the hypotheses, evidence and attempts.

## 5. Verify
Run `/verify`. If the fix touched routes, queries, auth or validation, run `/secure-check` first.

## Output checklist
- [ ] Symptom and expected behavior
- [ ] Root cause (`file:line`, why)
- [ ] Regression test name, failing-before and passing-after output
- [ ] Fix summary and files changed
- [ ] Same pattern found elsewhere (list, or "none")
- [ ] `/verify` table
