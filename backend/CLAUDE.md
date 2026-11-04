# Backend (Laravel API)

Laravel 13 JSON API under `/api`. PHP 8.3 locally through Herd (`php`); the Docker image uses PHP 8.4. Rules are in `/.claude/rules/`. The lint, test and static-analysis commands are defined only in the `/verify` skill.

## Everyday commands (run inside `backend/`)

| Command | Purpose |
| --- | --- |
| `php artisan serve` | API on http://localhost:8000 |
| `php artisan route:list --path=api` | List API routes and their middleware |
| `php artisan make:migration <name>` | New migration (see `/db-change`) |
| `php artisan make:request <Name>Request` | New FormRequest. Then change it to extend `ApiRequest` |
| `php artisan make:resource <Name>Resource` | New API Resource |
| `php artisan make:test <Name>Test` | New feature test in `tests/Feature` |
| `php artisan db:seed --class=PermissionSeeder` | Load new permission keys (asks first) |

`php artisan migrate`, `db:seed` and package installs ask for approval first. `migrate:fresh`, `reset`, `refresh` and `db:wipe` are blocked.

## Where things go

| Concern | Location |
| --- | --- |
| Routes and their middleware groups | `routes/api.php` |
| Middleware aliases, exception → JSON mapping | `bootstrap/app.php` |
| Rate limiters (`login`, `signup-resend`, `api`), lazy-loading guard | `app/Providers/AppServiceProvider.php` |
| Validation | `app/Http/Requests/*Request.php` (extend `ApiRequest`) |
| Business logic and transactions | `app/Services/*Service.php` |
| Response shape | `app/Http/Resources/*Resource.php` + `app/Http/Traits/ApiResponse.php` |
| Shop isolation | `app/Models/Concerns/BelongsToShop.php`, `app/Models/Scopes/ShopScope.php` |
| Roles and permissions | `app/Support/PermissionCatalog.php`, `app/Http/Middleware/*`, `User::hasPermission()` |
| Exact money | `app/Support/Money.php` |
| Business-rule errors (409) | `app/Exceptions/BusinessRuleException.php` |

## Facts to know before you change code

- Tests use in-memory SQLite (`phpunit.xml`); production uses MySQL. Code and migrations MUST work on both.
- `Model::preventLazyLoading()` is on outside production, so a missing `with()` fails in tests.
- The Docker `CMD` runs `php artisan migrate --force` on every container start. **A merged migration runs in production on the next deploy, with no manual step.**
- PHPStan runs at level 5 over `app/` (`phpstan.neon`). Pint uses its default Laravel preset, with no `pint.json`.
- After every edit to a backend PHP file, a PostToolUse hook runs Pint on that file.
