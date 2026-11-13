# 2. Subscription and multi-gateway payment modules

- Status: Accepted
- Date: 2026-11-12

## Context

Shops will pay the platform for a plan. Payments come through Bangladeshi gateways (bKash, Nagad, SSLCommerz) and bank transfer. The existing code has no subscription, no queue worker in production, no Redis, and a `payments` table that already holds what customers pay a shop. Gateway callbacks are unreliable (users close the app before the callback), amounts must never drift, and a late failure must never undo a confirmed payment.

## Decision

1. **Two modules, one boundary.** Billing (`App\Models\Billing`, `App\Services\Billing`) owns plans, subscriptions, invoices, coupons and entitlements. Gateway (`App\Models\Gateway`, `App\Services\Gateway`) owns gateway settings, attempts, confirmed payments, refunds, logs and the ledger. They share no service calls. The gateway module writes an `outbox_events` row inside the transaction that confirms a payment; `PublishOutbox` dispatches it as a Laravel event; a billing listener extends the subscription. This is the transactional outbox pattern.
2. **Tenant column is `shop_id`**, as in every other table, with the `BelongsToShop` scope. Super-admin endpoints use `withoutGlobalScope` under `role:super_admin`, as the tenancy rule already allows.
3. **Money in whole paisa, stored as integers** (`*_paisa` columns) in these two modules. This differs from the `decimal(14,2)` columns elsewhere; the `Money` helper already computes in paisa, so no conversion is needed inside the modules. Existing tables are not changed.
4. **Table names** avoid the existing `payments`: `payment_attempts`, `gateway_payments`, `gateway_refunds`, `gateway_logs`, `ledger_entries`.
5. **Gateways are strategies behind `PaymentGatewayInterface`, resolved by code through `GatewayRegistry`.** Credentials live only in `gateway_configs` (encrypted JSON cast, key = `APP_KEY`), never in code or `.env`. Business code never branches on a gateway name.
6. **Statuses only move forward** (`initiated → pending → success | failed | cancelled | expired`; `success → partially_refunded → refunded`), enforced in the service under a row lock. A webhook is stored and queued, never trusted; the worker verifies with the gateway API and re-checks amount and currency.
7. **Queue and cache stay on the database drivers** for now (`jobs`, `cache`, `cache_locks` tables exist). The module needs a worker (`queue:work --queue=payments,notifications,reports`) and the scheduler (`schedule:run` every minute) in production; these are deployment changes, documented in the README. Moving to Redis later changes config only.
8. **No repository layer**, per ADR 0001 and the architecture rule. Eloquent models remain the data layer.
9. **Deferred, to be their own ADRs:** monthly partitioning (MySQL partitioning forbids foreign keys on the partitioned table, which conflicts with the schema rule), a read replica for reports, and SMS delivery (no provider exists; reminders go by email behind a small `SmsSender` interface with a log driver).

## Consequences

- Existing shops are put on an inactive `legacy` plan by the first migration, so enabling enforcement locks nobody out; the super admin moves them to real plans.
- Every money write in these modules goes through a service with `DB::transaction` + `lockForUpdate`, and every state change has a test for the repeat case (duplicate webhook, double verification).
- Deployment must add the worker and the scheduler before webhooks or reminders work. Until then, payments stay `pending` and reconciliation does not run.
- Two conventions now coexist for money storage (decimal taka vs integer paisa). The rule file notes which applies where.
