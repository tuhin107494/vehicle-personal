# Subscriptions and gateway payments

How shops subscribe to a plan and pay for it. The design decisions are in [ADR 0002](adr/0002-subscription-and-gateway-payments.md). This page covers running it.

## How it fits together

```
shop owner → /billing → BillingController → SubscriptionService → invoice (unpaid)
          → /billing/pay/{invoice} → CheckoutService → gateway (bKash or bank transfer)
gateway callback / webhook → VerifyPaymentAttempt (queue) → PaymentVerifier
          → gateway_payments + ledger_entries + outbox_events  (one transaction)
outbox → PublishOutbox → PaymentSucceeded → ExtendSubscriptionOnPayment → SubscriptionService::renew()
```

- The payment side never touches subscriptions. It writes an outbox event, and the subscription side reacts to it.
- A webhook payload is never trusted. It only names an attempt. The queued job asks the gateway's API what happened.
- Money is stored as whole paisa in `*_paisa` columns. A shop with no subscription row is not blocked, and a feature a plan does not list is allowed.

## Required in production

Two long-running processes are needed, or nothing past checkout works. The Docker image only runs migrate and serve, so add both to the deploy.

| Process | Command | Why |
| --- | --- | --- |
| Queue worker | `php artisan queue:work --queue=payments,notifications,reports --tries=3` | Verifies payments, publishes the outbox, sends renewal reminders |
| Scheduler | `* * * * * php artisan schedule:run` (cron, every minute) | Runs the jobs in the next table |

Queue and cache use the database drivers (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`), so Redis is not required. Restart the worker after every deploy so it loads new code.

### Scheduled jobs

| Command | When | Does |
| --- | --- | --- |
| `PublishOutbox` job | every minute | Sends pending outbox events. The safety net for a missed nudge |
| `payments:reconcile-pending` | every 5 minutes | Re-verifies bKash attempts still open after 10 minutes |
| `billing:roll-statuses` | every 15 minutes | Trial or active → past due → expired, by the clock |
| `billing:sync-usage` | hourly | Writes cached usage counters to `feature_usage` |
| `billing:issue-renewals` | daily 01:00 | Creates the next period's invoice 7 days before the end |
| `payments:compare-settlements` | daily 02:00 | Compares yesterday's payments with the gateway and the ledger. Mismatches show under Payments → Settlement check |
| `billing:send-reminders` | daily 09:00 | Email and SMS 7, 3 and 1 days before the end |

Each command can be run by hand. `payments:compare-settlements --date=2026-11-12` checks a given day.

## Environment variables

Only the ones this module adds or relies on. See `backend/.env.example` for the rest.

| Variable | Default | Meaning |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `database` | Must not be `sync` in production, or webhooks block |
| `FRONTEND_URL` | `http://localhost:3000` | Where gateways send the payer back (`/billing/payments/{id}`) and where reminders link to |
| `APP_URL` | | The public API address. Gateway callback and webhook URLs are built from it |
| `SMS_DRIVER` | `log` | `log` writes texts to the log. A provider driver is added in `AppServiceProvider` |
| `MAIL_*` | | Renewal reminders use the normal mail settings, or the SMTP settings saved in System settings |

Gateway credentials are **not** env vars. The super admin saves them in the app, and they are stored encrypted with `APP_KEY`. Rotating `APP_KEY` makes saved credentials unreadable, so they would have to be entered again.

## Platform settings

Under System settings the super admin sets the plan new shops trial on (`billing.trial_plan`, blank means the cheapest plan with a trial) and the grace days after a period ends (`billing.grace_days`, default 7).

## Configuring a gateway

1. Sign in as the super admin and open **Payment gateways**.
2. Open **Configure** for the gateway and fill in the sandbox credentials. Secrets are write-only: a saved one shows as a masked tail, and a blank field keeps it.
3. Copy the URLs shown at the bottom of the page (callback, success, fail, cancel, webhook) into the gateway's merchant portal.
4. Press **Test connection**. For bKash this requests a fresh token, which proves the username, password, app key and app secret together.
5. Set the amount limits and fee, switch the gateway on, and try a sandbox payment as a shop owner.
6. To go live, switch **Active mode** to live and enter your password. Every setting change is written to the admin audit log, without secret values.

### bKash

Uses tokenized checkout without agreements (mode `0011`). The base URL is a credential field, so sandbox and live can differ.

The integration is bKash **Tokenized Checkout**, which bKash marks as its recommended product. It supports both API versions. The **Base URL** you save picks the version: one containing `/v2` uses v2, anything else uses v1.2.0-beta. Ask bKash which version your merchant account is on.

| Version | Sandbox base URL | Source followed |
| --- | --- | --- |
| v2 (current docs) | `https://tokenized.sandbox.bka.sh/v2/tokenized-checkout` | developer.bka.sh/docs |
| v1.2.0-beta | `https://tokenized.sandbox.bka.sh/v1.2.0-beta` | bKash's WooCommerce plugin |

- Live hosts use `tokenized.pay.bka.sh` instead of `tokenized.sandbox.bka.sh`. bKash's docs do not print the v2 live URL, so confirm it with bKash.
- bKash allows only two token grant or refresh calls an hour, and blocks the app for an hour after that. The token is cached for 50 minutes, renewed with the refresh token, and fetched by one process at a time. Test connection reuses a cached token.
- bKash does not report a cancelled or failed payment as such: it stays "Initiated". The attempt here is closed as expired after its 30-minute window.
- Saved-wallet agreements are not implemented.

### Bank transfer

The shop sees the bank details, pays outside the app, and submits the transfer reference and, if required, a receipt photo. The super admin approves or rejects it under **Payment gateways → Bank transfers**. Approval goes through the same verifier as a gateway answer.

## Adding a gateway

1. Create `app/Services/Gateway/Gateways/<Name>Gateway.php` implementing `PaymentGatewayInterface`: `code`, `fields` and `credentialSchema` (what the configure form asks for), `initiate`, `verify`, `handleWebhook`, `refund`, `queryStatus`, `testConnection`.
2. Add one line to `BUILT_IN` in `GatewayRegistry`.
3. Add a row for its code in `GatewayConfigSeeder` and run the seeder.

The configure page, checkout list, webhook route and logs work from the schema, so no controller or screen changes. Use `GatewayHttp` for outbound calls so requests are retried and logged with secrets scrubbed. `verify` must return only what the gateway's API says, and the verifier checks amount and currency against the invoice.

## Super admin screens

| Screen | Path |
| --- | --- |
| Payment gateways, bank transfers | `/platform-gateways` |
| Plans and coupons | `/platform-plans` |
| Every shop's subscription, manual extension | `/platform-subscriptions` |
| Payments, refunds, settlement check, revenue | `/platform-payments` |

Shop owners use `/billing`.

## Operating notes

- **A payment succeeded but the plan did not start.** Check the queue worker is running and that no outbox row is stuck: `select * from outbox_events where status <> 'published'`. A row marked `failed` has `last_error`. Fix the cause and set it back to `pending`.
- **A shop is locked after paying.** The scheduler or worker was probably down. `php artisan billing:roll-statuses` is safe to run, and the outbox publisher will apply the pending renewal.
- **Refunds.** Done from Payments by the super admin. A refund of a bank transfer is recorded as offline, since there is no gateway to call.
- **Recovery for the migrations.** They only add tables and one nullable column. `php artisan migrate:rollback --step=4` reverses them. The billing migration also creates a hidden Legacy plan and a 10-year subscription for every existing shop. Rolling back drops those rows with the tables.

## Known gaps

- Phases 6 to 8 of this module (scheduled jobs, tenant and admin screens, this page) ship **without automated tests**, by decision. Phases 2 to 5 have `BillingSchemaTest`, `SubscriptionTest`, `GatewayPaymentTest` and `BkashGatewayTest`.
- bKash follows bKash's docs (v2) and plugin code (v1.2.0-beta), but has not yet made a real sandbox payment. The v2 path has no automated test, only a manual check against faked bKash answers.
- bKash's callback signature is not checked, because its docs do not say how. It doesn't need to be: the callback only names the payment, and bKash's API is asked what happened.
- SMS only has the log driver.
- Partitioning of large tables and a read replica are not done.
