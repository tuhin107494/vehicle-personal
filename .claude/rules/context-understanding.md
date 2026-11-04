# Context understanding

Applies to every task, before any edit.

## Before changing code
- MUST read the file you will change in full, plus every caller (`grep -rn "<symbol>" backend/app backend/routes frontend/app frontend/components frontend/lib`).
- MUST read the tests that cover the code (`grep -rln "<route or class>" backend/tests`).
- MUST find the closest existing example of what you are building (another Service, FormRequest, Resource, page or form) and follow its shape. Examples: `PaymentService` for money writes, `Booking*` for a shop-owned resource, `CustomerForm.tsx` for a zod form.
- MUST check whether a helper already exists before writing a new one: `Money`, `ApiRequest::shopExists()` / `shopUnique()` / `category()`, `ApiResponse::perPage()` / `paginated()`, `api()` / `applyServerErrors()`, `DataTable`, `SearchSelect`.

## Verify, never invent
- MUST NOT reference a file, class, method, route, config key, env var, npm or Composer package, or artisan command without first confirming it exists, with `ls`, `grep`, `php artisan route:list`, `composer.json` or `package.json`.
- MUST NOT add a dependency without the user's approval (see [git-workflow.md](git-workflow.md) and the ask list in `.claude/settings.json`).
- When a Next.js API is involved, MUST read the matching guide in `frontend/node_modules/next/dist/docs/` first.
- MUST NOT claim a test, lint or build passed unless you ran it in this session and saw the result.

## Assumptions and questions
- MUST state each assumption in one line before acting on it (for example: "Assuming moderators may view but not create bookings").
- MUST ask the user, and not guess, when any of these is unclear:
  - which role or permission may use the feature
  - money rules: rounding, who pays, refund amounts
  - whether data is per shop or platform-wide
  - whether an existing API field may change shape
- SHOULD ask one focused question with a recommended answer, not a list of open-ended ones.

## Scope
- MUST change only what the task needs. Unrelated fixes go in the final report as suggestions.
- MUST list in the final report every file changed, and why.
