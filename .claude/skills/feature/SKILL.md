---
name: feature
description: Build or change a whole feature across the Laravel API and the Next.js dashboard (schema, model, validation, service, resource, route, permission, types, page, Bangla strings, tests) so no layer is missed. Use for a new screen, a new business flow, or a new field that shows in the UI. For a single endpoint with no UI, use /api-endpoint instead.
---

# Feature, end to end

Rules: [context-understanding](../../rules/context-understanding.md), [architecture-solid](../../rules/architecture-solid.md), [multi-tenancy-and-roles](../../rules/multi-tenancy-and-roles.md), [database-acid](../../rules/database-acid.md), [api-design](../../rules/api-design.md), [frontend](../../rules/frontend.md), [testing](../../rules/testing.md), [security](../../rules/security.md).

## 1. Discover
1. Read the closest existing feature end to end: its migration, model, request, service, resource, controller, route, page, form and test.
2. Grep for every caller of anything you will change (per context-understanding).
3. Write down: what is stored, who may use it (role and `group.action` key), each business rule, and whether data is per shop.

**Stop and ask the user** if the role, money rules, or per-shop vs platform scope is unclear.

## 2. Plan
List every file to create or change, grouped by layer. Show the plan to the user before writing code if it touches more than 10 files or changes an existing response field.

## 3. Implement, in this order
1. **Schema:** run `/db-change` for the migration.
2. **Model:** `BelongsToShop` (shop data), fillable fields, `casts()`, typed relations.
3. **FormRequest:** extends `ApiRequest`, a bounded rule for every field, `shopExists`/`shopUnique` for shop references.
4. **Service:** every write beyond single-model CRUD, following database-acid.
5. **Resource:** only the fields the UI uses, relations through `whenLoaded`.
6. **Controller:** `HasMiddleware` with a `permission:` per action. It validates, calls the Service and returns the envelope. It eager-loads what the Resource reads.
7. **Route** in `routes/api.php` in the right group. New permission keys go in `PermissionCatalog::all()`.
8. **Types** in `frontend/lib/types.ts` that match the Resource.
9. **UI:** a page in `app/(dashboard)/<feature>/`, a form in `components/forms/`, calls through `api()`, and all four states (frontend rule).
10. **Menu entry**, if the feature is a screen. Hide it for users without the permission.
11. **Strings:** every new English string goes in `lib/bn.ts`.

## 4. Test
Run `/write-tests` for the new behavior. It covers the full list in the testing rule.

## 5. Review and verify
1. Run `/secure-check` on the diff.
2. Run `/verify`. Follow its bounded failure handling (at most 2 fixes per step, then stop and report).

## Output checklist
- [ ] Files changed, grouped by layer
- [ ] Permission key(s) added, and the roles that should get them
- [ ] Migration notes (deploy-safe? recovery?)
- [ ] Tests added (names)
- [ ] `/secure-check` result
- [ ] `/verify` table
- [ ] Assumptions made and open questions
