# Vehicle Management

A multi-shop system for vehicle dealers. It tracks stock, purchases, sales, installments, due collections, investors and their profit settlements, along with reports. The UI is available in English and Bangla (বাংলা).

The repository holds two apps:

| Folder | Stack | Purpose |
| --- | --- | --- |
| [`backend/`](backend) | Laravel 13, PHP 8.3+, Sanctum, MySQL | JSON REST API under `/api` |
| [`frontend/`](frontend) | Next.js 16 (App Router), React 19, TypeScript, Tailwind CSS 4, shadcn/ui | Web dashboard |

```
Browser ──► Next.js (localhost:3000) ──Bearer token──► Laravel API (localhost:8000/api) ──► MySQL
```

---

## Quick start

### Prerequisites

- PHP **8.3+** and Composer
- Node.js **20+** and npm
- MySQL 8 (or MariaDB)
- Optional: [Laravel Herd](https://herd.laravel.com) on macOS or Windows provides PHP, Composer and a local domain

### 1. Backend (API)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# create the database named in .env (default: vehicle_management)
mysql -u root -e "CREATE DATABASE vehicle_management"

php artisan migrate --seed          # schema + permissions, categories and demo users
php artisan serve                   # http://localhost:8000
```

Optional sample data (customers, vehicles, sales and history):

```bash
php artisan db:seed --class=DemoSeeder
```

### 2. Frontend (dashboard)

```bash
cd frontend
npm install
npm run dev                         # http://localhost:3000
```

The frontend calls `http://localhost:8000/api` by default. If your API runs somewhere else, for example on a Herd domain, create `frontend/.env.local`:

```env
NEXT_PUBLIC_API_URL=http://vehicle-management.test/api
```

In that case, also set `APP_URL` and `FRONTEND_URL` in `backend/.env` to match, so that CORS and password-reset links work.

### 3. Log in

`php artisan migrate --seed` creates these accounts. The password for all of them is `password`.

| Role | Email | Can do |
| --- | --- | --- |
| Shop Owner | `owner@example.com` | Everything in the shop **Demo Motors**, plus users and role permissions |
| Admin | `admin@example.com` | Whatever the shop owner grants the `admin` role |
| Moderator | `moderator@example.com` | Whatever the shop owner grants the `moderator` role |

> Change or remove these accounts before deploying anywhere public.

---

## Key concepts

### Multi-tenancy (shops)

Every business record belongs to a **shop**. Models that use the [`BelongsToShop`](backend/app/Models/Concerns/BelongsToShop.php) trait get the global [`ShopScope`](backend/app/Models/Scopes/ShopScope.php) automatically, so queries only return rows for the signed-in user's `shop_id`. A super admin has no shop and therefore sees no shop data.

When you add a new shop-owned model, give it a `shop_id` column and use `BelongsToShop`. Don't filter by shop by hand.

### Roles and permissions

- Roles are fixed: `super_admin`, `shop_owner`, `admin` and `moderator` (see [`PermissionCatalog`](backend/app/Support/PermissionCatalog.php)).
- Permission keys look like `group.action`, for example `sales.create` and `vehicles.view`. They are defined in `PermissionCatalog::all()` and seeded by `PermissionSeeder`.
- A shop owner decides which permissions the `admin` and `moderator` roles have in their shop.
- Route middleware aliases are registered in [`bootstrap/app.php`](backend/bootstrap/app.php):
  - `role:<roles>`: the user must have one of the listed roles
  - `shop`: the user must belong to a shop
  - `permission:<key>`: the user's role must hold that permission (applied inside the controllers)

To add a permission, add it to `PermissionCatalog::all()`, run `php artisan db:seed --class=PermissionSeeder`, and check it in the controller.

### Business flow

```
Purchase (from a supplier, optionally funded by investors)
   └─► Vehicle in stock
          └─► Sale (to a customer: cash or installment plan)
                 ├─► Payments / due collections / promises to pay
                 ├─► Registration tracking
                 └─► Investor profit settlement
```

Vehicles are created through **purchases** and never directly. The domain logic lives in [`app/Services`](backend/app/Services) (`PurchaseService`, `SaleService`, `PaymentService`, …), so keep controllers thin.

---

## Project structure

### Backend

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/   # one controller per resource
│   │   ├── Requests/          # FormRequest validation (extend ApiRequest)
│   │   ├── Resources/         # JSON transformers
│   │   ├── Middleware/        # role, shop, permission checks
│   │   └── Traits/ApiResponse.php
│   ├── Models/                # Eloquent models (+ Concerns/, Scopes/)
│   ├── Services/              # business logic and transactions
│   ├── Support/               # PermissionCatalog, ShopSettings
│   └── Exceptions/BusinessRuleException.php
├── database/{migrations,seeders,factories}
├── routes/api.php             # every API route
└── tests/{Feature,Unit}
```

### Frontend

```
frontend/
├── app/
│   ├── (auth)/          # login, forgot-password, reset-password
│   └── (dashboard)/     # one folder per screen: vehicles, sales, purchases, reports, …
├── components/          # shared components (DataTable, SearchSelect, AppShell, …)
│   ├── forms/
│   └── ui/              # shadcn/ui primitives
└── lib/
    ├── api.ts           # fetch wrapper: base URL, bearer token, error handling
    ├── auth.tsx         # auth context / current user
    ├── i18n.tsx, bn.ts  # English ⇄ Bangla translation
    └── types.ts         # shared TypeScript types for API data
```

---

## API conventions

- Base URL: `/api`. Every route except `login`, `forgot-password` and `reset-password` requires `Authorization: Bearer <token>`. The token comes from `POST /api/login`.
- Every response uses the same envelope (from [`ApiResponse`](backend/app/Http/Traits/ApiResponse.php)):

  ```json
  { "success": true, "message": "OK", "data": { }, "meta": { } }
  ```

  ```json
  { "success": false, "message": "Validation failed", "errors": { "field": ["…"] } }
  ```

- Paginated lists put their pagination info in `meta`.
- To reject an action that breaks a business rule (for example, selling a vehicle that is already sold), throw `BusinessRuleException`. It is returned as a clean JSON error.
- See [`routes/api.php`](backend/routes/api.php) for the full list of endpoints.

---

## Day-to-day commands

### Backend (run inside `backend/`)

| Command | What it does |
| --- | --- |
| `php artisan serve` | Start the API on port 8000 |
| `composer test` | Run the PHPUnit suite (in-memory SQLite, so your DB is untouched) |
| `php artisan test --filter=VehicleFlowTest` | Run a single test class |
| `vendor/bin/pint` | Fix code style (Laravel Pint) |
| `vendor/bin/phpstan analyse` | Static analysis (Larastan, level 5) |
| `php artisan migrate:fresh --seed` | Rebuild the database from scratch. **This deletes all data.** |
| `php artisan route:list --path=api` | List the API routes |

### Frontend (run inside `frontend/`)

| Command | What it does |
| --- | --- |
| `npm run dev` | Dev server on port 3000 |
| `npm run lint` | ESLint |
| `npm run build` | Production build (also type-checks) |
| `npm start` | Serve the production build |

### Before opening a PR

```bash
cd backend  && vendor/bin/pint && vendor/bin/phpstan analyse && composer test
cd frontend && npm run lint && npm run build
```

---

## Adding a feature: checklist

1. **Migration:** add `shop_id` if the data belongs to a shop.
2. **Model:** use `BelongsToShop` for shop data.
3. **FormRequest** in `app/Http/Requests` for validation.
4. **Service** method for any logic beyond simple CRUD. Wrap multi-table writes in `DB::transaction`.
5. **Controller** in `Controllers/Api`, returning through the `ApiResponse` helpers and checking the permission.
6. **Route** in `routes/api.php`, inside the correct middleware group.
7. **Permission** (if new) in `PermissionCatalog`.
8. **Frontend:** add a page in `app/(dashboard)/<feature>`, types in `lib/types.ts`, and calls through `lib/api.ts`.
9. **Translation:** add Bangla strings to `lib/bn.ts`.
10. **Tests:** add a feature test in `backend/tests/Feature`.

---

## Troubleshooting

- **`SQLSTATE[HY000] [1049] Unknown database`:** create the database named in `DB_DATABASE` first.
- **Login works but every request returns 401, or you see CORS errors:** make sure `FRONTEND_URL` in `backend/.env` matches the address the frontend is served from, and that `NEXT_PUBLIC_API_URL` points at the API. Restart both servers after changing env files.
- **The super admin sees empty lists:** this is expected. Super admins manage shops, not shop data. Log in as `owner@example.com`.
- **A new permission doesn't appear:** re-run `php artisan db:seed --class=PermissionSeeder`.
- **Dates or times look off:** the app timezone is set by `APP_TIMEZONE` (`.env.example` sets it to `Asia/Dhaka`).
