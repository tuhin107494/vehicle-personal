---
paths:
  - "backend/**"
---
# Error handling and logging

## Typed errors
| Situation | Throw | Becomes |
| --- | --- | --- |
| Business rule refused (overpayment, already sold, has dependents) | `App\Exceptions\BusinessRuleException` with a plain user message | 409 |
| Missing role or permission | `abort(403, 'You do not have permission to do this.')` or middleware | 403 |
| Bad input | FormRequest rules, or `ValidationException::withMessages([...])` for checks in a Service | 422 |
| Record not found or not in this shop | `findOrFail` / route-model binding | 404 |

- MUST NOT return an error with a 200 status, or `success: false` with a 2xx.
- MUST NOT add a new exception class when one of the above fits. A new one MUST be mapped in `bootstrap/app.php` `withExceptions()`.

## Global handler
- `bootstrap/app.php` is the **only** place exceptions become JSON. MUST NOT catch exceptions in a controller just to format them.
- 500 responses show `Server error` unless `APP_DEBUG=true`. MUST NOT put stack traces, SQL, file paths or env values in a response message.
- `BusinessRuleException` is in `dontReport` because it is an answer to the user, not a bug.

## No swallowed exceptions
- A `catch` MUST either rethrow, convert to one of the typed errors above, or log with `Log::error`/`Log::warning` **and** return a defined error. An empty `catch {}` or `catch (\Throwable) { return null; }` is forbidden.
- Code after an external call (mail, Google, HTTP) MUST handle failure explicitly. A mail failure MUST NOT roll back a committed payment (see [database-acid.md](database-acid.md)).

## Logging
- Use the `Log` facade. Channel and level come from config (`LOG_CHANNEL`, `LOG_LEVEL`).
- Every log call MUST pass a context array with at least `shop_id` and `user_id` (when there is a user) and the record ID:
  ```php
  Log::warning('Mail send failed after payment', ['shop_id' => $user->shop_id, 'user_id' => $user->id, 'sale_id' => $sale->id]);
  ```
- There's no request-ID middleware yet (decision pending), so don't refer to a request ID that doesn't exist.
- MUST NOT log passwords, tokens, `Authorization` headers, full request bodies, NID numbers, phone numbers, emails or bank details. Log IDs instead.
- Levels: `error` when an operation failed and needs a human; `warning` when it degraded but worked; `info` for rare business events. No `debug` logging in committed code.
