---
paths:
  - "backend/**"
  - "frontend/lib/api.ts"
  - "frontend/lib/types.ts"
---
# API design

## Routes
- Every route lives in `backend/routes/api.php` under `/api`, inside the correct middleware group (see [multi-tenancy-and-roles.md](multi-tenancy-and-roles.md)).
- REST: plural kebab-case nouns (`/vehicle-colors`, `/due-collection`). Use `Route::apiResource` for CRUD. Nested routes go one level deep at most (`/sales/{sale}/payments`).
- Actions that aren't CRUD are `POST` to a verb sub-path of the resource (`POST /sales/{sale}/cancel`). MUST NOT use `GET` for anything that changes data.
- **Versioning:** the API is unversioned (decision pending). So a change MUST NOT remove or rename a response field, or make an optional request field required, unless the same PR updates every caller in `frontend/` and `lib/types.ts`.

## One envelope
Every response goes through `ApiResponse` and `bootstrap/app.php`:
```json
{ "success": true,  "message": "OK", "data": {}, "meta": {} }
{ "success": false, "message": "Validation failed", "errors": { "field": ["..."] } }
```
- Success: `$this->success($data, 'Message', $status)`. Lists: `$this->paginated($paginator, Resource::class)`.
- MUST NOT build a JSON response by hand with another shape.

## Status codes
| Code | When |
| --- | --- |
| 200 | Read, update, delete, or action done (delete returns `success(null, '<Thing> deleted')`) |
| 201 | Created |
| 401 | No or expired token (from the handler) |
| 403 | Missing role or permission (`abort(403)` / middleware) |
| 404 | Not found, **including another shop's record** |
| 409 | Business rule refused (`BusinessRuleException`) |
| 422 | Validation failed (FormRequest) |
| 429 | Rate limited (throttle) |
| 500 | Unexpected. The message is hidden unless `APP_DEBUG` |

## Pagination, filtering and sorting
- Lists MUST paginate with `->paginate($this->perPage($request))`. `per_page` is 1–100, default 15. `meta` carries `current_page`, `last_page`, `per_page` and `total`.
- Filters and sort fields MUST be validated, and sort columns MUST come from an allowlist.

## Request and response validation
### Requests
- Every endpoint that takes input to change data (`POST`, `PUT`, `PATCH`, or a `DELETE` with a body or query) MUST type-hint a FormRequest (`app/Http/Requests/*Request.php`, extends `ApiRequest`). This includes auth, sign-up and action endpoints (`/login`, `/signup`, `/collections/call`, `/customers/{customer}/merge`).
- MUST NOT call `$request->validate(...)` or `Validator::make(...)` in a controller for these endpoints, and MUST NOT hide one in a private helper (`private function validated(Request $request)`).
- Read the input only through `$request->validated()` or `$request->safe()->only([...])`, never `$request->all()` or `$request->input()` for data that is saved (see [security.md](security.md)).
- Two endpoints with the same input share one Request (`EmailRequest` for forgot password and resend link; `CallRequest` for call mark, unmark and history). Shared rule pieces live as `public const` on one Request (`SignupRequest::SHOP_RULES`), not copied.
- Custom messages go in the Request's `messages()`, not in the controller.
- Not needed: an endpoint with no input (a `DELETE` of a route model, `/logout`). `GET` list filters MAY validate inline, but they MUST be validated (see Pagination above).
- A FormRequest validates before the controller method runs. A check that must answer first, whatever the input (sign-up closed), goes in middleware (`HasMiddleware` on the controller), not at the top of the method.
- Checks that need the database or a business rule (the record exists, the plan is this investor's) go in the Request as a rule (`shopExists()->where(...)`) or in the Service as `BusinessRuleException` / `ValidationException::withMessages` — not as extra `validate()` calls in the controller.

### Responses
- Every model, or collection of models, in a response MUST go through a Resource (`app/Http/Resources/<Model>Resource.php`): `Resource::make($m)`, `Resource::collection($list)`, or `$this->paginated($paginator, Resource::class)`. This includes a model nested in another payload (`['payment' => PurchasePaymentResource::make($p)]`) and data preloaded with another response (the categories sent with `/me`).
- MUST NOT pass a model, a query result (`->get()`, `->first()`) or `$model->toArray()` to `$this->success()`. To add a field next to a Resource, use `Resource::make($m)->resolve() + [...]`.
- A Resource lists its fields explicitly (no `parent::toArray()` in new Resources), so a new column is never exposed by accident. Relations use `whenLoaded()` and return only the fields the page needs (`->only(['id', 'name'])`).
- Fields are snake_case and money is a number with 2 decimals. Dates are `Y-m-d` (`->toDateString()`). Datetimes are ISO 8601 in UTC: return the value itself (`'created_at' => $this->created_at`) so it is written like every other response (`2026-03-10T04:00:00.000000Z`).
- Moving a response to a Resource MUST keep every field and format a caller reads (check `frontend/` and the mobile app). Only fields nobody reads (`shop_id`, `created_by`, `updated_at`) may be dropped.
- Not needed: computed data that is not a model (reports, summaries, totals, `['photo_url' => ...]`). Build it as a plain array with the same field rules.
- A new or changed Resource MUST update its type in `frontend/lib/types.ts` in the same PR (see Contract below).

## Contract
- There is no OpenAPI spec (decision pending). The contract is the Resource class plus the matching type in `frontend/lib/types.ts`. A change to one MUST update the other in the same PR.
- `php artisan route:list --path=api` is the source of truth for the endpoint list.
