# Coding standards

The tools enforce these. The commands are defined in `/verify`.

## Tools are the source of truth
- PHP style: Pint, default Laravel preset (no `pint.json`). It runs automatically after each PHP edit.
- PHP types: Larastan level 5 over `backend/app` (`phpstan.neon`).
- TS/React: ESLint (`frontend/eslint.config.mjs`) and TypeScript `strict` (`frontend/tsconfig.json`).
- MUST NOT add `@phpstan-ignore*`, baseline entries, `eslint-disable`, `@ts-ignore`, `@ts-expect-error` or `any` to get past a check. Fix the cause.
- MUST NOT reformat code you aren't changing (the Pint hook formats whole edited files, so mention that in the PR if it touched unrelated lines).

## Typing
- PHP: every new method declares parameter and return types. Arrays with a known shape get a PHPDoc type (`@return array<int, Payment>`), as `PaymentService` does. Relations declare generics (`@return BelongsTo<Shop, $this>`).
- TS: no `any`. API data uses types from `lib/types.ts`. Unknown JSON is `unknown` and is narrowed before use.

## Naming (match what exists)
| Thing | Convention | Example |
| --- | --- | --- |
| Controller | `<Resource>Controller` in `Controllers/Api` | `BookingController` |
| FormRequest | `Store<X>Request` / `Update<X>Request`, or `<X>Request` for both | `StorePaymentRequest`, `CustomerRequest` |
| Service | `<Domain>Service`, verb methods | `SaleService::cancel()` |
| Resource | `<Model>Resource` | `SaleResource` |
| Table / column | snake_case plural / snake_case | `due_promises.paid_at` |
| Permission key | `group.action` | `payments.collect` |
| React component file | PascalCase `.tsx` | `SearchSelect.tsx` |
| Hooks, functions, variables | camelCase | `applyServerErrors` |
| Route segment | kebab-case | `/due-collection` |

## Size and shape
- A function SHOULD stay under ~40 lines and a class or component file under ~400. A file that is already larger MUST NOT grow by a new responsibility; extract it instead.
- At most 3 levels of nesting. Use early returns and guard clauses.
- Comments explain **why**, in one short line, like the existing ones. No comments that repeat the code.

## Hygiene
- MUST NOT leave dead code, commented-out code, `dd()`, `dump()`, `ray()`, `var_dump()` or `console.log`.
- MUST NOT add `TODO`/`FIXME` without an issue reference (`// TODO(#123): ...`).
- User-facing messages are plain sentences ending in a full stop, like the existing `BusinessRuleException` messages. The frontend translates them through `lib/bn.ts`.
- Dates and times use `Carbon`/`now()`/`today()` with the app timezone (`APP_TIMEZONE`). Never `date()` or `time()`.
