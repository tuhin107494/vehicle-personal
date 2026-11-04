# Security

Highest priority (see the order in `/CLAUDE.md`). Shop isolation and permission rules live in [multi-tenancy-and-roles.md](multi-tenancy-and-roles.md); this file covers everything else.

## Mapped to OWASP Top 10 (2021) and API Security Top 10 (2023)

| Risk | Control in this repo (MUST) |
| --- | --- |
| A01 Broken access control / API1 BOLA / API5 function-level authz | Follow [multi-tenancy-and-roles.md](multi-tenancy-and-roles.md) |
| API3 Property-level authz / mass assignment | Only `$request->validated()` reaches `create`/`update`/`fill`. Every model declares fillable fields (the `$fillable` property or the `#[Fillable]` attribute). Never `$guarded = []`. Never put `role`, `shop_id` or `is_active` in user-editable requests unless the endpoint exists to change them and checks the actor's role |
| API3 Excessive data exposure | Responses go through a Resource. Never return a model or `->toArray()` directly. Never expose `password`, `remember_token` or token hashes |
| A02 Crypto failures | Passwords are hashed by Laravel's `hashed` cast/`Hash` (bcrypt, `BCRYPT_ROUNDS=12`). MUST NOT store, log or return a plain password. Random tokens use `Str::random()` or `random_bytes()`, never `rand()`/`mt_rand()` |
| A03 Injection | Use Eloquent or the query builder with bindings. `whereRaw`, `selectRaw`, `orderByRaw` and `DB::select` take `?` bindings, never interpolated variables. Sort and filter column names come from a hard-coded allowlist |
| A03 XSS | See [frontend.md](frontend.md) (no `dangerouslySetInnerHTML` with API or user data) |
| A04 Insecure design / API6 business flows | Money flows follow [database-acid.md](database-acid.md). Rules are checked server-side under a row lock |
| A05 Misconfiguration | `APP_DEBUG=false` in production. CORS origins come only from `FRONTEND_URL` (`config/cors.php`). MUST NOT add `*` origins or set `supports_credentials` without approval. MUST NOT loosen the CSP or headers in `frontend/next.config.ts` without stating why in the PR |
| A06 Vulnerable components | Before adding or upgrading a package, run `composer audit` / `npm audit --omit=dev` and report the results. Adding a package needs user approval |
| A07 Auth failures | Every non-public route is inside `auth:sanctum`. Tokens expire (`config/sanctum.php`: 7 days). Password change and reset revoke other tokens, as `AuthController` already does. Login keeps its timing-safe dummy hash |
| API4 Resource consumption | Lists use `perPage()` (max 100). Public endpoints carry a `throttle:` middleware. Every route carries the global `api` limiter (120/min) |
| A08 Integrity | Uploaded files are never executed or included. Never `unserialize()` request data |
| A09 Logging failures | See [error-handling-logging.md](error-handling-logging.md) |
| A10 SSRF / API10 unsafe consumption | Outbound HTTP (for example Google sign-in) goes only to fixed hosts in config, never to a URL from the request. Validate third-party responses before use |
| API9 Inventory | Every route is in `routes/api.php`. MUST NOT leave debug or test routes behind |

## Validation at every boundary
- Every request field MUST have a FormRequest rule with a type and a bound (`string|max:255`, `integer|min:1`, `numeric|min:0`, `date`, `in:...`, `boolean`).
- Route parameters are resolved through scoped route-model binding, so another shop's record returns 404.
- Data from third parties (Google token payloads, mail providers) MUST be validated before it is used.

## Uploads
- MUST use rules like `PhotoRequest`: `image`, an explicit `mimes:` list and `max:` in KB.
- MUST store files under a generated name (`$file->store(...)`), never a name or path from the client.
- MUST delete a file only by a path read from the database row, never from the request.

## Secrets
- Secrets come only from `.env` through `config/*.php`. `env()` MUST NOT appear outside `config/` (there are none today).
- MUST NOT put a secret in code, tests, seeders, logs, exceptions, responses or any `NEXT_PUBLIC_*` variable. Everything `NEXT_PUBLIC_*` is shipped to the browser.
- A new env var MUST be added to `backend/.env.example` with a safe placeholder value.

## Known accepted risk (decision pending)
- The bearer token is in `localStorage`, so it can be read by injected script. The CSP in `next.config.ts` is the mitigation. MUST NOT add inline script sources or new script hosts without approval.
