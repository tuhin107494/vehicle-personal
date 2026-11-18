<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Notifications\SubscriptionEndingSoon;
use Illuminate\Support\Facades\DB;

/**
 * A shop's subscription: the free trial a new shop starts on, and extra days the super admin gives.
 * Payments, plan changes and invoices are not here yet; see the subscription-module branch.
 */
class SubscriptionService
{
    /** What a locked shop is told when it tries to change something. It can still sign in, view and export. */
    /** Days before the end when the shop owner is emailed. */
    public const REMINDER_DAYS = [3, 1];

    public const ENDED_MESSAGE = 'Your subscription has ended, so your shop is read-only. Contact support to make changes again.';

    /** The shop's current subscription (its latest), from anywhere: a request, a worker or the scheduler. */
    public function current(int $shopId): ?Subscription
    {
        return Subscription::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->with('plan')->latest('id')->first();
    }

    /** A shop is locked only when it has a subscription that has run out. A shop with none is not held back. */
    public function locked(?Shop $shop): bool
    {
        $current = $shop ? $this->current($shop->id) : null;

        return $current !== null && ! $current->isUsable();
    }

    /**
     * Puts a shop that has just opened on the trial plan. Safe to repeat: a shop that already has a subscription keeps it.
     * Nothing happens when no plan offers a trial.
     */
    public function startTrial(Shop $shop): ?Subscription
    {
        $plan = $this->trialPlan();

        if (! $plan) {
            return null;
        }

        return DB::transaction(function () use ($shop, $plan) {
            Shop::lockForUpdate()->findOrFail($shop->id); // two openings at once (confirm + approve) make one trial

            if ($current = $this->current($shop->id)) {
                return $current;
            }

            $now = now();
            $end = $now->copy()->addDays($plan->trial_days);

            return Subscription::create([
                'shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'trialing',
                'starts_at' => $now, 'ends_at' => $end, 'trial_ends_at' => $end, 'auto_renew' => false,
            ]);
        });
    }

    /**
     * The super admin adds days: counted from the end date while it still runs, or from now once it has ended.
     * A trial stays a trial (with a later end); anything else becomes active again.
     */
    public function extend(Shop $shop, int $days): Subscription
    {
        return DB::transaction(function () use ($shop, $days) {
            $current = Subscription::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id)
                ->lockForUpdate()->latest('id')->first();

            if (! $current) {
                throw new BusinessRuleException('This shop has no subscription to extend.');
            }

            $from = $current->isUsable() ? $current->ends_at : now();
            $end = $from->copy()->addDays($days);
            $trial = $current->status === 'trialing';

            $current->update([
                'status' => $trial ? 'trialing' : 'active',
                'ends_at' => $end,
                'trial_ends_at' => $trial ? $end : $current->trial_ends_at,
                'cancelled_at' => null,
                'grace_ends_at' => null,
                'last_reminder_days' => null, // a new end date: its reminders go out again
            ]);

            return $current->fresh('plan');
        });
    }

    /**
     * Emails the owner of each running trial or subscription that ends within 3 days: once at 3 days left, once at 1.
     * Days are calendar days. last_reminder_days remembers the last one sent, so a daily run never repeats it, and a
     * shop first seen at 1 day left (say, extended by one day) only gets the 1-day email. Returns how many were sent.
     */
    public function sendExpiryReminders(): int
    {
        $sent = 0;
        $first = max(self::REMINDER_DAYS);

        Subscription::withoutGlobalScope(ShopScope::class)
            ->whereIn('status', ['trialing', 'active'])
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', today()->addDays($first)->endOfDay())
            ->whereHas('shop', fn ($q) => $q->where('is_active', true))
            ->with(['plan', 'shop.owner'])
            ->each(function (Subscription $subscription) use (&$sent) {
                $days = (int) today()->diffInDays($subscription->ends_at->copy()->startOfDay());
                $window = collect(self::REMINDER_DAYS)->sort()->first(fn (int $d) => $days <= $d); // 3 → 3, 2 → 3, 1 → 1, 0 → 1
                $owner = $subscription->shop->owner;

                if (! $owner || ($subscription->last_reminder_days !== null && $subscription->last_reminder_days <= $window)) {
                    return;
                }

                try {
                    $owner->notify(new SubscriptionEndingSoon($subscription, $days));
                    $subscription->update(['last_reminder_days' => $window]);
                    $sent++;
                } catch (\Throwable $e) {
                    report($e); // one bad address must not stop the others; it is tried again on the next run
                }
            });

        return $sent;
    }

    /** The plan new shops start a trial on: the cheapest active plan that offers one. */
    public function trialPlan(): ?Plan
    {
        return Plan::where('is_active', true)->where('trial_days', '>', 0)->orderBy('price_paisa')->orderBy('sort_order')->first();
    }
}
