# Vehicle Management

A multi-shop SaaS for vehicle dealers: stock, purchases, sales, installments, due collection, investors and reports, in English and Bangla. Setup, folder structure and the API envelope are in [README.md](README.md). Layers and data flow are in [docs/architecture.md](docs/architecture.md).

| Folder | Stack | Own instructions |
| --- | --- | --- |
| `backend/` | Laravel 13, PHP 8.3 (Herd `php`), Sanctum, MySQL (SQLite in tests), Composer | [backend/CLAUDE.md](backend/CLAUDE.md) |
| `frontend/` | Next.js 16 App Router, React 19, TypeScript strict, Tailwind 4, shadcn/ui, npm | [frontend/CLAUDE.md](frontend/CLAUDE.md) |

## Rules

The rules live in `.claude/rules/`, one concern per file. Claude Code loads them automatically, and the backend-only and frontend-only files load only while you work on matching paths. **Each rule exists in exactly one file.** Other files link to it rather than repeating it.

| File | Covers |
| --- | --- |
| [context-understanding.md](.claude/rules/context-understanding.md) | Read before changing; verify before claiming; when to ask |
| [security.md](.claude/rules/security.md) | OWASP Top 10 and API Top 10 as they apply here |
| [multi-tenancy-and-roles.md](.claude/rules/multi-tenancy-and-roles.md) | Shop isolation, roles, permissions |
| [architecture-solid.md](.claude/rules/architecture-solid.md) | Layers, SOLID, DRY/KISS/YAGNI |
| [coding-standards.md](.claude/rules/coding-standards.md) | Style, typing, naming, dead code |
| [database-acid.md](.claude/rules/database-acid.md) | Transactions, locking, money, migrations, deletes |
| [api-design.md](.claude/rules/api-design.md) | Routes, envelope, status codes, pagination, contract |
| [error-handling-logging.md](.claude/rules/error-handling-logging.md) | Exceptions, the global handler, logging |
| [testing.md](.claude/rules/testing.md) | What to test and how |
| [frontend.md](.claude/rules/frontend.md) | Components, states, a11y, i18n, XSS |
| [git-workflow.md](.claude/rules/git-workflow.md) | Branches, commits, PRs |

**Priority when two rules conflict:** security > data integrity > correctness > readability > performance. A change that would break a higher-priority rule to satisfy a lower one MUST NOT be made. Stop and report the conflict instead.

## Skills

| Skill | Use when |
| --- | --- |
| `/feature` | Building or changing a feature across both apps |
| `/api-endpoint` | Adding or changing one API endpoint |
| `/db-change` | Writing a migration, or code that writes several rows or tables |
| `/write-tests` | Adding tests for existing or new behavior |
| `/debug-issue` | Something behaves wrong and the cause is unknown |
| `/refactor` | Restructuring code without changing behavior |
| `/secure-check` | Security review of a diff or module |
| `/pr-review` | Reviewing a branch or PR against these rules |
| `/verify` | **The only "done" gate.** It defines every lint, typecheck, test and build command |

Read-only subagents live in `.claude/agents/`: `code-reviewer` and `security-auditor`.

## Definition of done

A change is done only when **all** of these hold:
1. `/verify` reports no new failures in changed files, and all tests and the build pass.
2. Every behavior change has a test, as set out in [testing.md](.claude/rules/testing.md).
3. Every layer the feature touches is updated, following the `/feature` checklist.
4. `/secure-check` passes for changes to routes, requests, models, services, auth, uploads or rendering of API data.

Failure handling, including the limit on fix attempts, is defined in `/verify`.

## Hard limits for the agent

- `.claude/settings.json` blocks secret files and destructive database and git commands. MUST NOT look for a way around a denied or blocked command. Report it to the user instead.
- Secrets: [security.md](.claude/rules/security.md). Commits, pushes and packages: [git-workflow.md](.claude/rules/git-workflow.md).
- MUST NOT edit `vendor/`, `node_modules/`, `.next/` or `.claude/settings.local.json`.
- No skill or step may repeat itself without a limit. Every retry is capped as that skill says.
