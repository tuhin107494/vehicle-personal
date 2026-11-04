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
- Requests: a FormRequest per write endpoint (see [security.md](security.md)).
- Responses: a Resource per model. Fields are snake_case and money is a number with 2 decimals. Dates are `Y-m-d`; datetimes are ISO 8601.

## Contract
- There is no OpenAPI spec (decision pending). The contract is the Resource class plus the matching type in `frontend/lib/types.ts`. A change to one MUST update the other in the same PR.
- `php artisan route:list --path=api` is the source of truth for the endpoint list.
