# Subscriptions (basic)

Every shop has a subscription: the plan it is on and when it ends. This is the plan-and-subscription part of the full
subscription module (branch `subscription-module`), without payments, invoices, coupons, plan features or gateways.
The `plans` and `subscriptions` tables match that module column for column, so it can be added on top later.

## Rules

- **New shops get a 7-day free trial** when they open: the owner confirms their email, signs up with Google, the super
  admin approves a signed-up shop, or the super admin creates one. A shop waiting for confirmation has no trial yet.
- The trial plan is the cheapest active plan with `trial_days > 0`. The migration seeds one: **Free trial** (`trial`, 7 days).
  Change its `trial_days` to change the trial length.
- **Shops that existed before** are put on a hidden **Legacy** plan for 10 years, so nobody is locked out on deploy.
- **When a subscription ends, the shop is locked**: sign-in is refused, and every shop route answers 403 with
  "Your subscription has ended. Contact support to continue." `GET /api/me` still works, so the app can show why. Data is kept.
  The end is read from `ends_at` on each request, so no scheduler or queue worker is needed.
- A shop with no subscription row (only possible when no trial plan is active) is not locked.
- **The super admin extends** from **Shops → Extend** (`POST /api/shops/{shop}/subscription/extend`, `days` 1 to 365).
  Days are added from the end date while it still runs, or from now once it has ended. A trial stays a trial; anything
  else becomes `active`.

## Where it lives

- Backend: `app/Models/Billing/{Plan,Subscription}.php`, `app/Services/Billing/SubscriptionService.php`,
  `app/Http/Middleware/EnsureActiveSubscription.php` (alias `subscription`), `app/Http/Controllers/Api/Billing/SubscriptionController.php`,
  migration `2026_11_18_100000_create_plans_and_subscriptions_tables.php`, tests in `tests/Feature/SubscriptionTrialTest.php`.
- Hooks: trial start in `ShopService::create`, `SignupController::confirm` and `ShopController::update`; the sign-in check in
  `AuthController::signIn`; `subscription` in the shop route groups and around vehicle-categories in `routes/api.php`;
  `Shop::subscription()`; `subscription` in `User::authData()` and `ShopResource`.
- Frontend: `lib/subscription.ts`, `components/SubscriptionBanner.tsx` (3 days or fewer left, or ended),
  `components/SubscriptionCard.tsx` (profile page), `components/forms/ExtendSubscriptionModal.tsx` and the Subscription
  column on the Shops page.

## Adding the full module later

The full module's billing migration creates `plans` and `subscriptions` too. When bringing it in, drop those two tables
(and the Legacy rows) from its migration, or guard them with `Schema::hasTable`, and keep this migration.
