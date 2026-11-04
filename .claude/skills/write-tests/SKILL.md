---
name: write-tests
description: Write PHPUnit feature or unit tests for vehicle-management in the style of the existing suite (RefreshDatabase, seeded permissions, ShopService shops, Sanctum::actingAs), covering happy path, validation, business rules, permissions and cross-shop isolation. Use when adding tests for new or existing behavior, or a regression test for a bug.
---

# Write tests

Rules: [testing](../../rules/testing.md), [multi-tenancy-and-roles](../../rules/multi-tenancy-and-roles.md) (required tests section).

## 1. Discover
1. Find the code under test and its routes (`php artisan route:list --path=api`).
2. Look for an existing test class to extend: `ls backend/tests/Feature backend/tests/Unit`, and `grep -rln "<route or class>" backend/tests`.
3. Read `TenancyAndRolesTest.php` for the shop, user and acting-as helpers, and copy them rather than inventing new ones.

## 2. List the cases
Write the case list from the testing rule's "what every change needs" table before writing code. For a bug, the first case reproduces the bug.

## 3. Write
1. Add to the existing class for the feature, or create one with `php artisan make:test <Name>Test` (unit: `--unit`).
2. Use the setup, acting-as, assertion and determinism patterns from the testing rule.
3. One behavior per test method, named `test_<what>_<expected>`.

## 4. Run the new tests
Use the targeted test run from `/verify` with this test class.
- **New behavior:** the tests MUST pass. If one fails, decide whether the test or the code is wrong. Fix it, at most 2 attempts, then stop and report.
- **Regression test:** it MUST fail before the fix. Show the failing output, then apply the fix (in `/debug-issue`).

## 5. Verify
Run `/verify`.

## Output checklist
- [ ] Case list, with each case mapped to a test method name
- [ ] Test file(s) changed
- [ ] The filtered run's output (and the failing-before output, for a regression test)
- [ ] `/verify` table
