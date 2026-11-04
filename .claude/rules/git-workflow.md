# Git workflow

## Branches
- Base branch: `develop`. PRs merge into `develop` (as #9–#13 did). `main` is the release branch.
- MUST NOT commit directly to `develop` or `main`.
- New branch names SHOULD be `<type>/<short-kebab-summary>`, for example `feat/booking-refunds` or `fix/due-collection-reset`. `<type>` is one of the commit types below.

## Commits
- The agent MUST commit or push only when the user asks in this conversation. `git commit` and `git push` ask for approval through `.claude/settings.json`.
- New commit messages SHOULD follow Conventional Commits: `<type>(<scope>): <summary>`.
  - Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `build`.
  - Scopes: `backend`, `frontend`, or a feature name (`sales`, `bookings`).
  - Summary: imperative mood, under 72 characters.
- One logical change per commit. MUST NOT mix formatting-only changes with behavior changes.
- MUST NOT commit `.env*` (other than `.env.example`), `.claude/settings.local.json`, `vendor/`, `node_modules/`, `.next/` or build output. The root and app `.gitignore` files cover them.
- MUST NOT force-push, `reset --hard` or `clean -f`. These are blocked.

## Pull requests
- Small: SHOULD stay under ~400 changed lines, not counting lockfiles and generated files. Split bigger work into steps that each pass `/verify`.
- Title in Conventional Commits form. The body says what changed, why, how it was tested, and any migration or recovery notes.
- **PR checklist** (it matches the Definition of Done in `/CLAUDE.md`):
  - [ ] `/verify` output pasted: no new failures, tests and build pass
  - [ ] Tests added: happy path, 422, 409, 403, cross-shop ([testing.md](testing.md))
  - [ ] All layers updated: migration, model, request, service, resource, route, permission, `lib/types.ts`, page, `lib/bn.ts`
  - [ ] `/secure-check` passed (for routes, requests, models, services, auth, uploads, rendering)
  - [ ] Migration is safe to run automatically on deploy, and has a recovery note if it changes data
  - [ ] No new package, or the package was approved and the `composer audit` / `npm audit` result is pasted
