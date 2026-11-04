---
paths:
  - "backend/**"
  - "frontend/lib/auth.tsx"
  - "frontend/lib/menu.ts"
---
# Multi-tenancy and roles

The tenant is a **shop**. A user's shop is `users.shop_id`, and a `super_admin` has none.

## Tenant identity
- The shop MUST come from the authenticated user (`$request->user()->shop_id` or `auth()->user()`). MUST NOT read `shop_id` from the request body, query string or headers.
- MUST NOT accept `shop_id` in a shop-scoped FormRequest. `BelongsToShop` stamps it on create.

## Tenant-owned data
- Every table that holds shop data MUST have `$table->foreignId('shop_id')->constrained();`, and its model MUST `use BelongsToShop`.
- MUST NOT filter shop models by `shop_id` by hand. `ShopScope` does it.
- A `unique` index on shop data MUST include `shop_id` (for example a customer's phone is unique per shop).
- Validation that points at shop data MUST use `$this->shopExists('<table>')` and `$this->shopUnique('<table>', '<column>')` from `ApiRequest`. A bare `exists:` or `unique:` rule can match another shop's row.

## Where the global scope does not apply
These skip `ShopScope`, so the code MUST add `where('<table>.shop_id', $shopId)` to **every** shop table it touches:
- `DB::table()`, `DB::select()` and other query-builder code (as `User::permissions()` does with `role_permissions`)
- joins onto shop tables
- console commands, queued jobs and scheduled tasks, which have no signed-in user, so the scope is off

`withoutGlobalScope(s)` is allowed only:
- for platform rows that have `shop_id = NULL` (vehicle categories, see `VehicleCategoryController`), and
- in `role:super_admin` platform endpoints.

Each use MUST state the filter it applies instead, and MUST have a test showing shop B can't see shop A's rows through it.

## Roles and permissions (centralized)
- Roles are fixed in `PermissionCatalog`: `super_admin`, `shop_owner`, `admin`, `moderator`. MUST NOT add a role without approval.
- Route groups in `routes/api.php`: `auth:sanctum` → `shop` (shop users) or `role:super_admin` (platform).
- Every controller action that reads or changes shop data MUST declare `new Middleware('permission:<group>.<action>', only: [...])` in `middleware()`.
- Permission keys are `group.action`, defined in `PermissionCatalog::all()` and loaded by `PermissionSeeder`. MUST NOT check role names inline in controllers or services; use `$user->hasPermission()` or the middleware.
- `shop_owner` holds every permission. `admin` and `moderator` hold what the owner grants in `role_permissions`.
- The frontend MAY hide what a user can't do (`lib/auth.tsx`), but the API MUST still refuse it.

## Nested records (no IDOR)
- When a route has a parent and a child (`/sales/{sale}/installments/{installment}`), the code MUST check that the child belongs to the parent (`$installment->sale_id === $sale->id`, or load it through `$sale->plan->installments`). Otherwise it returns 404, or 422 for a body field.

## Required tests
Every new shop-owned resource MUST have tests (see [testing.md](testing.md)) proving:
- shop B gets 404 on shop A's record (show, update, delete)
- shop B's list doesn't include shop A's rows
- shop B can't reference shop A's record in a request field (422)
- a role without the permission gets 403
- a super admin gets 403 on shop endpoints
