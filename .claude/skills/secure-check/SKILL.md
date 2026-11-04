---
name: secure-check
description: Security review of the current diff or a named module in vehicle-management, against the OWASP Top 10 and the OWASP API Security Top 10 as mapped in the project's security and multi-tenancy rules. Use before finishing any change to routes, requests, models, services, auth, uploads or frontend rendering of API data, or when asked whether code is safe.
---

# Security check

Rules (the checklist source; don't restate them): [security](../../rules/security.md), [multi-tenancy-and-roles](../../rules/multi-tenancy-and-roles.md), [database-acid](../../rules/database-acid.md), [error-handling-logging](../../rules/error-handling-logging.md), [frontend](../../rules/frontend.md).

## 1. Scope
- **Diff:** `git diff $(git merge-base develop HEAD)`, plus `git ls-files --others --exclude-standard` for new files.
- **Module:** the files the user named, plus their routes (`php artisan route:list --path=api`), FormRequests, Services and Resources.

For a large scope (over ~15 files), you MAY delegate to the read-only `security-auditor` agent, passing the file list. Re-check each finding it returns before reporting it.

## 2. Review each changed file
For every row of the OWASP/API table in the security rule, and every rule in multi-tenancy-and-roles, record **pass**, **fail** (`file:line`, the attack, the fix) or **n/a**. Pay special attention to:
1. New or changed routes: middleware group and `permission:`.
2. Every query: is it shop-scoped? Raw SQL or joins without a `shop_id` filter? `withoutGlobalScope`?
3. Every FormRequest field: typed, bounded, `shopExists`/`shopUnique`?
4. Every write: only `validated()` used? Any mass-assignable sensitive field?
5. Every response: through a Resource? Any hidden or PII field leaked?
6. Money and state changes: transaction, lock, repeat check?
7. Logs and exceptions: secrets or PII?
8. Frontend: unsafe HTML, secrets in `NEXT_PUBLIC_*`, CSP changes?

## 3. Prove the tenancy and permission tests exist
For each new shop-owned resource or endpoint, name the test methods that cover cross-shop 404/422 and missing-permission 403. Missing ones count as a **fail**.

## 4. Fix or report
- If this skill runs inside `/feature`, `/api-endpoint` or `/refactor`: fix each fail, then re-check only that item, **at most 2 times**. If it still fails, stop and report.
- If the user asked only for a review: report and don't edit.

## 5. Verify
If any file was changed, run `/verify`.

## Output checklist
- [ ] Scope reviewed (files)
- [ ] Table of findings: risk ID, `file:line`, severity (critical, high, medium, low), attack, fix
- [ ] Tenancy and permission test names, or the missing tests
- [ ] Items fixed vs still open
- [ ] `/verify` table (if files changed)
