---
name: api-endpoint
description: Add or change one Laravel API endpoint in vehicle-management (route, FormRequest, Service method, Resource, permission, frontend type, tests). Use when the work is one endpoint or one action on an existing resource. For work that also adds screens, use /feature.
---

# API endpoint

Rules: [api-design](../../rules/api-design.md), [multi-tenancy-and-roles](../../rules/multi-tenancy-and-roles.md), [architecture-solid](../../rules/architecture-solid.md), [security](../../rules/security.md), [database-acid](../../rules/database-acid.md).

## 1. Discover
1. `cd backend && php artisan route:list --path=api` to find sibling routes and their middleware.
2. Read the controller, FormRequest, Resource and Service of the closest sibling endpoint, plus its test.
3. If you are changing an existing endpoint, grep `frontend/` for its path and for each response field you will touch.

**Stop and ask** if it's unclear which permission key guards the endpoint, or whether a response field may change.

## 2. Define the contract
Write down: method and path, permission key, request fields with rules, response `data` shape, and each status code it can return (per the status table in api-design).

## 3. Implement
1. **FormRequest** extending `ApiRequest`.
2. **Service method**, if it writes more than one row, touches money, or enforces a rule. For schema changes, run `/db-change`.
3. **Resource**, or reuse an existing one.
4. **Controller action** and its `permission:` entry in `middleware()`.
5. **Route** in the correct group in `routes/api.php`.
6. **Permission:** a new key goes in `PermissionCatalog::all()`.
7. **Frontend contract:** update `frontend/lib/types.ts` and every caller found in step 1.

## 4. Test
Run `/write-tests` for this endpoint: happy path, 422, 409, 403 and cross-shop.

## 5. Verify
Run `/secure-check` on the diff, then `/verify`.

## Output checklist
- [ ] Method, path and permission key
- [ ] Request rules and response shape
- [ ] Files changed
- [ ] Frontend callers updated (or "none")
- [ ] Tests added
- [ ] `/secure-check` and `/verify` results
