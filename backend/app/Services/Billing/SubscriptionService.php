<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\Coupon;
use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\OutboxEvent;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A shop's subscription over its life: trial, paid periods, plan changes, grace and expiry.
 * Every change runs in a transaction under a row lock, clears the shop's cached entitlements after commit,
 * and records a subscription.changed event in the outbox.
 */
class SubscriptionService
{
    public const DEFAULT_GRACE_DAYS = 7;

    public function __construct(private BillingService $billing, private EntitlementCache $entitlements) {}

    /** The shop's current subscription (its latest), from anywhere: a request, a worker or the scheduler. */
    public function current(int $shopId): ?Subscription
    {
        return Subscription::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->with('plan')->latest('id')->first();
    }

    /** Puts a new shop on its trial. Nothing happens when no plan offers one (enforcement then waits for a plan). */
    public function startTrial(Shop $shop, ?Plan $plan = null): ?Subscription
    {
        $plan ??= $this->trialPlan();

        if (! $plan) {
            return null;
        }

        return DB::transaction(function () use ($shop, $plan) {
            if ($this->current($shop->id)) {
                throw new BusinessRuleException('This shop already has a subscription.');
            }

            $now = now();
            $days = max($plan->trial_days, 1);

            return $this->open($shop->id, $plan, 'trialing', $now, $now->copy()->addDays($days), $now->copy()->addDays($days));
        });
    }

    /**
     * Starts (or restarts, after expiry) a paid plan: a new subscription awaiting its first payment, with an invoice.
     * The period only begins when the payment module reports the invoice paid (renew()).
     *
     * @return array{subscription: Subscription, invoice: Invoice}
     */
    public function subscribe(Shop $shop, Plan $plan, ?Coupon $coupon = null): array
    {
        $this->ensureOffered($plan);

        return DB::transaction(function () use ($shop, $plan, $coupon) {
            $current = $this->lockedCurrent($shop->id);

            if ($current?->isUsable() && $current->status !== 'trialing') {
                throw new BusinessRuleException('This shop already has a running subscription. Use upgrade or downgrade to change the plan.');
            }

            $now = now();
            $end = $this->billing->periodEnd($plan, $now);
            // a trial keeps its plan until the invoice is paid (renew() switches it); an expired shop gets a fresh subscription awaiting payment
            $subscription = $current && $current->status === 'trialing'
                ? $current
                : $this->open($shop->id, $plan, 'past_due', $now, $now, null, graceEnd: $now->copy()->addDays(BillingService::INVOICE_DUE_DAYS));
            $invoice = $this->billing->createInvoice($subscription, $plan, $now, $end, $plan->price_paisa, $coupon);

            if ($invoice->total_paisa === 0) {
                $this->settle($invoice); // a free plan or a 100% coupon: no payment to wait for
            }

            $this->changed($subscription, 'subscribed');

            return ['subscription' => $subscription->fresh('plan'), 'invoice' => $invoice->fresh()];
        });
    }

    /**
     * Moves to a dearer plan at once. The shop owes the prorated difference for the rest of the period (an invoice);
     * from a trial, it is a plain subscribe to the new plan.
     *
     * @return array{subscription: Subscription, invoice: Invoice|null}
     */
    public function upgrade(Shop $shop, Plan $to, ?Coupon $coupon = null): array
    {
        $this->ensureOffered($to);

        return DB::transaction(function () use ($shop, $to, $coupon) {
            $current = $this->lockedCurrent($shop->id);

            if (! $current?->isUsable()) {
                throw new BusinessRuleException('There is no running subscription to upgrade. Choose a plan to subscribe.');
            }

            if ($current->status === 'trialing') {
                return $this->subscribe($shop, $to, $coupon);
            }

            if ($to->price_paisa <= $current->plan->price_paisa) {
                throw new BusinessRuleException('That plan is not an upgrade. Choose downgrade to move to a cheaper plan.');
            }

            $owed = $this->billing->calculateProration($current, $to);
            $current->update(['plan_id' => $to->id, 'pending_plan_id' => null]);
            $invoice = $owed > 0 ? $this->billing->createInvoice($current, $to, now(), $current->ends_at, $owed, $coupon) : null;

            if ($invoice && $invoice->total_paisa === 0) {
                $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            }

            $this->changed($current, 'upgraded');

            return ['subscription' => $current->fresh('plan'), 'invoice' => $invoice?->fresh()];
        });
    }

    /** Marks a cheaper plan to take over when the current period ends. Nothing changes until then. */
    public function downgrade(Shop $shop, Plan $to): Subscription
    {
        $this->ensureOffered($to);

        return DB::transaction(function () use ($shop, $to) {
            $current = $this->lockedCurrent($shop->id);

            if (! $current?->isUsable()) {
                throw new BusinessRuleException('There is no running subscription to downgrade.');
            }

            if ($to->price_paisa >= $current->plan->price_paisa) {
                throw new BusinessRuleException('That plan is not a downgrade. Choose upgrade to move to a dearer plan.');
            }

            $current->update(['pending_plan_id' => $to->id]);
            $this->changed($current, 'downgrade_scheduled');

            return $current->fresh('plan');
        });
    }

    /** Stops renewal. The shop keeps the plan until the paid period ends, then the subscription is cancelled (not expired). */
    public function cancel(Shop $shop, bool $immediately = false): Subscription
    {
        return DB::transaction(function () use ($shop, $immediately) {
            $current = $this->lockedCurrent($shop->id);

            if (! $current?->isUsable()) {
                throw new BusinessRuleException('There is no running subscription to cancel.');
            }

            $current->update([
                'auto_renew' => false, 'cancelled_at' => now(), 'pending_plan_id' => null,
            ] + ($immediately ? ['status' => 'cancelled', 'ends_at' => now()] : []));
            $this->changed($current, 'cancelled');

            return $current->fresh('plan');
        });
    }

    /** A paid invoice starts or extends the period it covers. Called by the payment listener and by a free subscribe; safe to repeat. */
    public function renew(Invoice $invoice): Subscription
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = Invoice::withoutGlobalScope(ShopScope::class)->lockForUpdate()->findOrFail($invoice->id);
            $subscription = Subscription::withoutGlobalScope(ShopScope::class)->lockForUpdate()->with('plan')->findOrFail($invoice->subscription_id);

            if ($invoice->status === 'void') {
                throw new BusinessRuleException('This invoice was voided, so it cannot start a period.');
            }

            // already applied (a repeated event): nothing to do
            if ($invoice->status === 'paid' && $subscription->ends_at->gte($invoice->period_ends_at) && $subscription->status === 'active') {
                return $subscription;
            }

            $invoice->update(['status' => 'paid', 'paid_at' => $invoice->paid_at ?? now()]);
            $subscription->update([
                'plan_id' => $invoice->plan_id,
                'status' => 'active',
                // a new period (the invoice reaches past the current end) starts afresh; a mid-period invoice (an upgrade) leaves the period as it is
                'starts_at' => $invoice->period_ends_at->gt($subscription->ends_at) ? $invoice->period_starts_at : $subscription->starts_at,
                'ends_at' => $invoice->period_ends_at->max($subscription->ends_at),
                'grace_ends_at' => null,
                'last_reminder_days' => null,
                'trial_ends_at' => $subscription->trial_ends_at,
                'pending_plan_id' => $subscription->pending_plan_id === $invoice->plan_id ? null : $subscription->pending_plan_id,
            ]);
            $this->changed($subscription, 'renewed');

            return $subscription->fresh('plan');
        });
    }

    /** The super admin gives time without a payment (a goodwill extension, an offline deal). Always audited. */
    public function extendManually(Shop $shop, Carbon $until, User $admin, ?string $note = null, ?Plan $plan = null): Subscription
    {
        return DB::transaction(function () use ($shop, $until, $admin, $note, $plan) {
            $current = $this->lockedCurrent($shop->id);

            if ($until->isPast()) {
                throw new BusinessRuleException('The new end date must be in the future.');
            }

            $before = $current?->ends_at?->toDateString();

            if ($current) {
                $current->update(['status' => 'active', 'ends_at' => $until, 'grace_ends_at' => null, 'cancelled_at' => null, 'last_reminder_days' => null] + ($plan ? ['plan_id' => $plan->id] : []));
            } else {
                if (! $plan) {
                    throw new BusinessRuleException('This shop has no subscription yet: choose the plan to put it on.');
                }

                $current = $this->open($shop->id, $plan, 'active', now(), $until);
            }

            AdminAuditLog::record($admin, 'subscription.extended', $current, [
                ['field' => 'ends_at', 'from' => $before, 'to' => $until->toDateString()],
                ['field' => 'note', 'to' => $note],
            ]);
            $this->changed($current, 'extended');

            return $current->fresh('plan');
        });
    }

    /**
     * Moves one subscription along the status flow by the clock: past the end → past_due (grace) or cancelled;
     * past the grace → expired; a scheduled downgrade is applied when a period ends. Returns the status it is in now.
     */
    public function rollStatus(Subscription $subscription, ?Carbon $at = null): string
    {
        $at ??= now();

        return DB::transaction(function () use ($subscription, $at) {
            $subscription = Subscription::withoutGlobalScope(ShopScope::class)->lockForUpdate()->findOrFail($subscription->id);
            $before = $subscription->status;

            if (in_array($subscription->status, ['trialing', 'active'], true) && $subscription->ends_at->lte($at)) {
                if ($subscription->cancelled_at || ! $subscription->auto_renew) {
                    $subscription->update(['status' => 'cancelled']);
                } else {
                    $subscription->update(['status' => 'past_due', 'grace_ends_at' => $subscription->ends_at->copy()->addDays($this->graceDays())]
                        + ($subscription->pending_plan_id ? ['plan_id' => $subscription->pending_plan_id, 'pending_plan_id' => null] : []));
                }
            } elseif ($subscription->status === 'past_due' && $subscription->grace_ends_at?->lte($at)) {
                $subscription->update(['status' => 'expired']);
            }

            if ($subscription->status !== $before) {
                $this->changed($subscription, 'status_rolled');
            }

            return $subscription->status;
        });
    }

    public function graceDays(): int
    {
        return PlatformSettings::get('billing.grace_days') ?: self::DEFAULT_GRACE_DAYS;
    }

    /** The plan new shops start a trial on: the one named in platform settings, else the cheapest active plan that offers a trial. */
    public function trialPlan(): ?Plan
    {
        $slug = PlatformSettings::get('billing.trial_plan');
        $named = $slug ? Plan::where('slug', $slug)->where('is_active', true)->first() : null;

        return $named ?? Plan::where('is_active', true)->where('trial_days', '>', 0)->orderBy('price_paisa')->orderBy('sort_order')->first();
    }

    private function lockedCurrent(int $shopId): ?Subscription
    {
        return Subscription::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->lockForUpdate()->with('plan')->latest('id')->first();
    }

    private function open(int $shopId, Plan $plan, string $status, Carbon $start, Carbon $end, ?Carbon $trialEnd = null, ?Carbon $graceEnd = null): Subscription
    {
        return Subscription::create([
            'shop_id' => $shopId, 'plan_id' => $plan->id, 'status' => $status, 'starts_at' => $start, 'ends_at' => $end,
            'trial_ends_at' => $trialEnd, 'grace_ends_at' => $graceEnd, 'auto_renew' => true,
        ]);
    }

    /** An invoice with nothing to pay starts its period at once. */
    private function settle(Invoice $invoice): void
    {
        $subscription = Subscription::withoutGlobalScope(ShopScope::class)->findOrFail($invoice->subscription_id);
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        $subscription->update(['plan_id' => $invoice->plan_id, 'status' => 'active', 'starts_at' => $invoice->period_starts_at, 'ends_at' => $invoice->period_ends_at, 'grace_ends_at' => null, 'last_reminder_days' => null]);
    }

    private function ensureOffered(Plan $plan): void
    {
        if (! $plan->is_active) {
            throw new BusinessRuleException('This plan is not available.');
        }
    }

    /** After the change commits: the cached entitlements go, and the other module hears about it through the outbox. */
    private function changed(Subscription $subscription, string $what): void
    {
        OutboxEvent::record(OutboxEvent::SUBSCRIPTION_CHANGED, ['subscription_id' => $subscription->id, 'shop_id' => $subscription->shop_id, 'change' => $what]);
        DB::afterCommit(fn () => $this->entitlements->forget($subscription->shop_id));
    }
}
