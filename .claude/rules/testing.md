# Testing

Backend: PHPUnit 12 (`backend/tests`), with in-memory SQLite through `phpunit.xml`. Frontend: no test runner yet (decision pending). The commands are defined in `/verify`.

## Pyramid as it applies here
| Level | Where | MUST cover |
| --- | --- | --- |
| Unit | `tests/Unit` | Pure logic with no DB or HTTP: `App\Support\*` (`MoneyTest`, `PermissionCatalogTest`) and any new pure helper |
| Feature (API + DB) | `tests/Feature` | Every endpoint and every Service path, through HTTP |
| End-to-end flows | `tests/Feature` (for example `VehicleFlowTest`) | Critical chains: purchase → stock → sale → payment → settlement |

There is no browser E2E tool. The critical flows MUST be covered by feature tests that chain the endpoints.

## What every change needs
- **New or changed endpoint:** happy path (status, envelope, saved rows), 422 for each validation group, 409 for each business rule (and nothing written), 403 without the permission, and the tenancy tests listed in [multi-tenancy-and-roles.md](multi-tenancy-and-roles.md).
- **Money logic:** exact amounts after several partial operations (no float drift), and totals that match the ledger.
- **Bug fix:** a regression test that fails before the fix and passes after it. Put it in the test class for that feature.
- **Migration:** the full test suite in `/verify` runs every migration, so a failing `up()` fails it.

## How to write them (match the existing tests)
- `use RefreshDatabase;`. Seed what the code needs in `setUp()`: `$this->seed(PermissionSeeder::class)` and `$this->seed(VehicleCategorySeeder::class)`.
- Create shops with `app(ShopService::class)->create([...], [...])`, as `TenancyAndRolesTest::makeShop()` does. Other users come from `User::factory()`.
- Act as a user with `Sanctum::actingAs($user)`. Call `auth()->forgetGuards()` before switching users within one test.
- Use the JSON helpers (`postJson`, `getJson`) and assert with `assertCreated`, `assertForbidden`, `assertNotFound`, `assertStatus(409)`, `assertJsonValidationErrors('field')` and `assertJsonPath`.
- Check the database with `assertDatabaseHas`, `assertDatabaseMissing` and `assertDatabaseCount`.
- Test names are `test_<what>_<expected>` in snake_case, as in the existing tests.

## Deterministic
- Time: `$this->travelTo(...)` or `$this->freezeTime()`. MUST NOT depend on today's real date when the result changes by day (due dates, overdue, expiry).
- Mail: `Mail::fake()` / `Notification::fake()`. HTTP: `Http::fake()`. Files: `Storage::fake()`.
- No real network. No order dependence between tests. No `sleep()`.
- Random data from factories MUST NOT affect what is asserted. Set the fields you assert on.

## Coverage
- No coverage tool is configured (decision pending). Instead, the review checks that every new public Service method and endpoint has at least one test.
