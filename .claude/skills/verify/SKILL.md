---
name: verify
description: The single "done" gate for vehicle-management. Runs Pint, PHPStan and PHPUnit for the backend, and ESLint, tsc and the Next.js build for the frontend, then reports real results. Use before saying any change is done, before a commit or PR, at the end of every other skill, or when asked whether everything still works. This is the only file that defines these commands.
---

# Verify

**Policy:** the existing code already has style, static-analysis and lint failures. A change passes when:
- **every file it changed is clean** in steps 2, 3 and 5, including errors that were already in that file before the change (if you touch a file, you clean it)
- **all** tests pass
- the typecheck and build pass

Failures in files the change didn't touch are reported in step 8 but don't block. Fixes for errors that were already in a file go in their own commit, separate from the behavior change. Checks are never weakened (see [coding-standards](../../rules/coding-standards.md)).

Run each step as **one** Bash call from the repo root (`git rev-parse --show-toplevel`). Shell variables don't carry over between calls. File lists go through `xargs`, because zsh doesn't split unquoted variables into words.

## 1. Find the changed files
```bash
BASE=$(git merge-base develop HEAD)
{ git diff --name-only --diff-filter=ACMR "$BASE"; git ls-files --others --exclude-standard; } | sort -u
```
If nothing under `backend/` changed, skip steps 2–4. If nothing under `frontend/` changed, skip steps 5–7.

## 2. Backend style (changed PHP files)
```bash
BASE=$(git merge-base develop HEAD)
FILES=$({ git diff --name-only --diff-filter=ACMR "$BASE"; git ls-files --others --exclude-standard; } | sort -u | grep '^backend/.*\.php$' | sed 's#^backend/##')
cd backend && if [ -n "$FILES" ]; then printf '%s\n' "$FILES" | xargs vendor/bin/pint --test; else echo "no changed PHP files"; fi
```
On failure: run the same command without `--test`, then re-check (this counts as fix attempt 1).

## 3. Backend static analysis (changed files under `backend/app`)
```bash
BASE=$(git merge-base develop HEAD)
FILES=$({ git diff --name-only --diff-filter=ACMR "$BASE"; git ls-files --others --exclude-standard; } | sort -u | grep '^backend/app/.*\.php$' | sed 's#^backend/##')
cd backend && if [ -n "$FILES" ]; then printf '%s\n' "$FILES" | xargs vendor/bin/phpstan analyse --memory-limit=1G --no-progress; else echo "no changed app files"; fi
```

## 4. Backend tests (always the full suite)
```bash
cd backend && composer test
```

## 5. Frontend lint (changed files)
```bash
BASE=$(git merge-base develop HEAD)
FILES=$({ git diff --name-only --diff-filter=ACMR "$BASE"; git ls-files --others --exclude-standard; } | sort -u | grep -E '^frontend/.*\.(ts|tsx|js|mjs)$' | sed 's#^frontend/##')
cd frontend && if [ -n "$FILES" ]; then printf '%s\n' "$FILES" | xargs npx eslint; else echo "no changed frontend files"; fi
```

## 6. Frontend typecheck (whole project)
```bash
cd frontend && npx tsc --noEmit
```

## 7. Frontend build (whole project)
```bash
cd frontend && npm run build
```

## 8. Existing failures (report only, once per run)
```bash
cd backend && vendor/bin/pint --test 2>&1 | tail -1; vendor/bin/phpstan analyse --memory-limit=1G --no-progress 2>&1 | tail -1
cd ../frontend && npx eslint . 2>&1 | tail -2
```

## Targeted test run (used by other skills while they work, not a substitute for step 4)
```bash
cd backend && php artisan test --filter=<TestClass>
```

## Failure handling (bounded)
1. A failure in steps 2–7 that involves a changed file (or any test or build failure) MUST be fixed in the code, not in the check.
2. After a fix, re-run **only** the failed step.
3. **At most 2 fix attempts per step.** If it still fails, stop and report the command, the first 30 lines of output, and what you tried. MUST NOT start a third attempt or switch to a different workaround.

## Output checklist
Report this table. Every row MUST come from a command run in this session:

| Step | Command | Result | Notes |
| --- | --- | --- | --- |
| 2 | pint --test (changed) | pass / fail / skipped | |
| 3 | phpstan (changed) | pass / fail / skipped | |
| 4 | composer test | pass (N tests) / fail | |
| 5 | eslint (changed) | pass / fail / skipped | |
| 6 | tsc --noEmit | pass / fail / skipped | |
| 7 | npm run build | pass / fail / skipped | |
| 8 | existing failures | counts | not blocking |

The overall verdict is **pass** only if every row in steps 2–7 is pass or skipped.
