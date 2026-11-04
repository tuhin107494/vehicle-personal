# Architecture

How Vehicle Management is built, taken from the code as of 2026-11-04. Setup and commands are in [README.md](../README.md). Rules for changing the code are in [`.claude/rules/`](../.claude/rules/). Decisions are recorded in [`adr/`](adr/).

## System

```
Browser ── Next.js 16 dashboard (frontend/, :3000)
              │  fetch via lib/api.ts, Authorization: Bearer <Sanctum token>
              ▼
           Laravel 13 API (backend/, :8000, /api)
              │  Eloquent
              ▼
           MySQL (production) · SQLite in memory (tests)
```

Deployment: `backend/Dockerfile` (PHP 8.4) runs `php artisan migrate --force` and then serves on `$PORT`. CORS allows the origins in `FRONTEND_URL`.

## Request lifecycle (backend)

1. **Route** in `routes/api.php`. Public routes (login, signup, password reset, Google) each have a `throttle:` limit. Everything else is inside `auth:sanctum`.
2. **Middleware:**
   - global `api` limiter (120/min per user or IP)
   - `role:<roles>` (`EnsureRole`)
   - `shop` (`EnsureShopUser`: the user has a shop and it's active)
   - per-action `permission:<group.action>` (`CheckPermission` → `User::hasPermission()`)
3. **Controller** (`app/Http/Controllers/Api`) receives a **FormRequest** (`app/Http/Requests`, extends `ApiRequest`) and route-model-bound models.
4. **Service** (`app/Services`) runs business rules and multi-row writes in `DB::transaction`, with `lockForUpdate`. Plain single-model CRUD stays in the controller.
5. **Model** (`app/Models`). Shop models use the `BelongsToShop` trait.
6. **Resource** (`app/Http/Resources`) shapes the output. `ApiResponse::success()` / `paginated()` wraps it in `{ success, message, data, meta }`.
7. **Errors** are mapped once, in `bootstrap/app.php`: 422 validation, 401, 403, 404, 409 `BusinessRuleException`, 500 (message hidden unless `APP_DEBUG`).

There is no repository layer. Eloquent models are the data-access layer (see [architecture-solid](../.claude/rules/architecture-solid.md)).

## Tenant isolation

- The tenant is a **shop** (`shops` table). Each user has `shop_id`; a `super_admin` has none.
- `BelongsToShop` adds `ShopScope`, which appends `where <table>.shop_id = <user's shop_id>` (or `= 0` for a user without a shop) to every Eloquent query while a user is signed in. It also stamps `shop_id` on create.
- Validation references go through `ApiRequest::shopExists()` / `shopUnique()`, so a request can't point at another shop's row.
- Not covered by the scope: raw `DB::` queries, joins, and code with no signed-in user (console, queues). These filter `shop_id` explicitly.
- Platform data: vehicle categories with `shop_id = NULL` are shared. Super-admin endpoints (`role:super_admin`) manage shops, permissions and platform settings.

## Roles and permissions

- Fixed roles: `super_admin`, `shop_owner`, `admin`, `moderator` (`App\Support\PermissionCatalog`).
- `shop_owner` holds every permission. `admin` and `moderator` hold what the owner grants, stored per shop in `role_permissions`.
- On sign-in, `/me` returns the user with their permission keys and preloaded shared lists, which the frontend uses to show or hide menu items and actions. The API checks the permission again on every request.

## Business flow and money

```
Purchase (supplier, optional investor funding) → Vehicle in stock
   → [Booking: advance holds the vehicle] → Sale (cash / due / installment plan)
       → Payments (PaymentService::collect): spread over installments, credit investor share,
         settle due promises, recompute totals from the ledger
       → Registration tracking → Investor settlement
```

- Vehicles are created only through purchases.
- All amounts are `decimal(14,2)` in the DB. Arithmetic uses `App\Support\Money` in integer paisa, to avoid float drift.
- `total_paid`, `due_amount` and `payment_status` are derived from the payments ledger (`PaymentService::recalculate()`).
- Sales are cancelled through a status. Purchases can be deleted only when nothing depends on them (`PurchaseService`). `Customer`, `Supplier`, `Investor` and `Vehicle` use soft deletes.

## Frontend

- App Router screens are in `app/(dashboard)/<feature>/page.tsx`, and sign-in screens in `app/(auth)/`. The public landing page is `app/page.tsx` at `/`, and the dashboard is at `/dashboard`.
- `lib/api.ts`:
  - It is the only HTTP client. It adds the bearer token from `localStorage` (`vm_token`).
  - It shares GET requests that are still in flight, and caches settings, categories and colors for 10 minutes. Any write clears that cache.
  - On a 401 it sends the user to `/login`, except for `/me`, which also runs on public pages (the dashboard layout redirects signed-out users itself). It turns 422s into form field errors (`applyServerErrors`).
- State: `lib/auth.tsx` (signed-in user and permissions) and `lib/i18n.tsx` (language) contexts. Everything else is local or react-hook-form state.
- Forms use react-hook-form + zod schemas that mirror the backend FormRequests.
- Translation: English text is the key in `lib/bn.ts`, used through `useT()` and `lib/domTranslate.ts`.
- Security headers and a strict CSP are set in `next.config.ts`.

## Quality gates

- **Backend:** PHPUnit feature and unit tests (`backend/tests`), Pint, Larastan level 5.
- **Frontend:** ESLint, TypeScript strict, `next build`.
- `.claude/skills/verify/SKILL.md` defines the commands.
- There is no CI pipeline in the repo yet.
