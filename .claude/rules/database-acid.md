---
paths:
  - "backend/**"
---
# Database and ACID

## Transactions (atomicity and isolation)
- Any write that changes more than one row or table, or touches money or stock status, MUST run inside `DB::transaction(function () { ... })` in a Service.
- Inside the transaction, MUST re-read every row whose state a rule depends on with `lockForUpdate()` before checking the rule (as `PaymentService::collect` does with the sale). Checks on a model loaded before the transaction are stale.
- Lock in a fixed order: parent before children (sale → plan → installments; purchase → vehicle) to avoid deadlocks.
- Isolation level: MySQL InnoDB's default (REPEATABLE READ) plus `lockForUpdate`. MUST NOT change the isolation level without an ADR. Note that SQLite tests don't exercise row locks.
- MUST NOT catch and swallow an exception inside a transaction. Let it roll back.
- MUST NOT send mail, call HTTP or delete files inside a transaction. Use `DB::afterCommit(fn () => ...)`, or do it after the transaction returns.

## Consistency
- Broken business rules throw `BusinessRuleException` (409) **inside** the transaction, after locking.
- Derived values (`total_paid`, `due_amount`, `payment_status`, installment status) MUST be recomputed from the ledger (`PaymentService::recalculate()`), never changed with `+=` from request data.
- Money arithmetic MUST go through `App\Support\Money` (integer paisa): `Money::paisa()` in, integer maths, `Money::taka()` out. MUST NOT add, subtract, multiply or `array_sum` taka floats.

## Idempotency (retries and double submits)
- Every state-changing service method MUST check the current state under the lock and refuse a repeat (already paid, already cancelled, vehicle already sold or booked), as `PaymentService::collect` and `SaleService::cancel` do.
- The current policy has no idempotency keys (decision pending). A double submit is stopped by the state check, plus the pending-button rule in [frontend.md](frontend.md).

## Constraints in the schema
- Every relation: `foreignId(...)->constrained()`. The default restrict-on-delete is required for ledger tables (sales, payments, purchases, purchase_payments, installments, investor settlements).
- NOT NULL by default; `nullable()` only when "no value" has a meaning.
- `unique` indexes for natural keys, including `shop_id` when the key is per shop.
- Money columns: `decimal(14, 2)`. MUST NOT use `float` or `double`.
- Enumerated status: `enum([...])` with a `default()`, as in the bookings table.
- Ranges (amount ≥ 0 and the like) are enforced in the FormRequest and re-checked in the Service. Laravel's Blueprint has no portable CHECK constraint for MySQL and SQLite.

## Performance
- No N+1 queries: eager-load with `with()` for everything a Resource reads (`whenLoaded`). Lazy loading throws outside production.
- `foreignId()->constrained()` creates the FK index on MySQL. Add explicit indexes for the columns lists filter or sort on (`status`, dates, `[vehicle_id, status]`).
- Aggregate in SQL (`sum`, `count`, `withSum`), not by loading rows into PHP.

## Migrations
- MUST create a new migration for every schema change. MUST NOT edit a migration already merged to `develop` or `main`.
- Every migration MUST have a `down()` that reverses `up()`.
- **Every merged migration runs in production automatically** (the Docker `CMD` runs `migrate --force`). So a migration MUST:
  - give new columns on existing tables `nullable()` or a `default()`, and backfill in the same migration if needed
  - not drop or rename a column the deployed code still reads. Do it in two releases: stop using the column, then drop it
  - work on MySQL and SQLite
- A migration that changes or deletes data MUST say in the PR how to recover, since `down()` can't restore lost data.

## Deletes, soft deletes and audit
- Soft deletes are used by `Customer`, `Supplier`, `Investor` and `Vehicle` today. A new model MUST NOT add `SoftDeletes` without approval (decision pending).
- A hard delete of a record with money or dependents MUST run in a Service or controller check that refuses when dependents exist (as `PurchaseService` and `CustomerController::destroy` do), and MUST recompute affected totals in the same transaction.
- Sales are cancelled via a status, never deleted.
- Audit today is the actor columns only (`created_by`, `received_by`, `called_by`). New money writes MUST record the acting user in such a column.
