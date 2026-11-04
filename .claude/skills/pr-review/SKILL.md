---
name: pr-review
description: Review a vehicle-management branch, PR or diff against the project rules (tenancy, permissions, ACID, layering, API contract, tests, frontend states, i18n) and report ranked findings without editing. Use when asked to review a PR, a branch or local changes before merge. Named pr-review so it doesn't clash with the built-in /code-review.
---

# PR review

Rules (the review criteria; don't restate them): every file in [.claude/rules/](../../rules/).

## 1. Get the diff
- **Local branch:** `git diff $(git merge-base develop HEAD)`, `git log --oneline $(git merge-base develop HEAD)..HEAD`, and `git ls-files --others --exclude-standard`.
- **GitHub PR:** `gh pr diff <number>` and `gh pr view <number>`.

## 2. Understand intent
Read the PR title, body or commits. Then read each changed file in full, along with its callers and tests (per context-understanding).

For large diffs (over ~15 files), you MAY delegate to the read-only `code-reviewer` agent with the file list. Re-check each finding it returns before reporting it.

## 3. Check, in priority order
1. **Security and tenancy:** apply the `/secure-check` steps (read-only mode).
2. **Data integrity:** transactions, locks, `Money`, migrations safe for automatic deploy, delete rules.
3. **Correctness:** logic errors, edge cases (zero, partial, overdue, cancelled), status codes.
4. **Integration:** all layers updated? `lib/types.ts` matches the Resource? `bn.ts` strings? permission seeded?
5. **Tests:** the required cases from the testing rule exist.
6. **Readability and standards:** coding-standards, architecture-solid.
7. **Performance:** N+1 queries, missing indexes, unbounded lists.

## 4. Verify findings
For each finding, re-read the exact lines and confirm that it's real. Drop anything you can't point to.

## 5. Report (no edits)
Don't change files and don't offer fixes unless asked.

## Output checklist
- [ ] Summary: what the PR does, in 2 lines
- [ ] Findings ranked most severe first: severity, `file:line`, the rule broken (link), the concrete failure scenario
- [ ] Missing tests, by name
- [ ] PR checklist from [git-workflow](../../rules/git-workflow.md), each item ticked or not
- [ ] Verdict: approve / request changes
