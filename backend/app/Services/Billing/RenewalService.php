<?php

namespace App\Services\Billing;

use App\Models\Billing\Invoice;
use App\Models\Billing\Subscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Notifications\RenewalReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What the scheduler does for subscriptions, in batches of BATCH rows: moves statuses along by the clock, raises the
 * next period's invoice before the current one ends, reminds owners, and writes cached usage counters to the database.
 * Every method runs with no signed-in user, so every query names the shop filter it needs itself.
 */
class RenewalService
{
    public const BATCH = 1000;

    /** Days before the end that a renewal invoice is raised, and the reminder windows (largest first). */
    public const INVOICE_DAYS_BEFORE = 7;

    public const REMINDER_DAYS = [7, 3, 1];

    public function __construct(private SubscriptionService $subscriptions, private BillingService $billing, private FeatureGateService $features) {}

    /**
     * Every subscription whose period or grace has run out moves on (trial/active → past_due or cancelled, past_due → expired).
     *
     * @return array<string, int> new status => how many moved to it
     */
    public function rollStatuses(?Carbon $at = null): array
    {
        $at ??= now();
        $moved = [];

        // the where no longer matches a row once it has moved, which is the case chunkById is safe for
        Subscription::withoutGlobalScope(ShopScope::class)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $due) => $due->whereIn('status', ['trialing', 'active'])->where('ends_at', '<=', $at))
                ->orWhere(fn (Builder $grace) => $grace->where('status', 'past_due')->where('grace_ends_at', '<=', $at)))
            ->chunkById(self::BATCH, function ($rows) use ($at, &$moved) {
                foreach ($rows as $subscription) {
                    $status = $this->subscriptions->rollStatus($subscription, $at);
                    $moved[$status] = ($moved[$status] ?? 0) + 1;
                }
            });

        return $moved;
    }

    /**
     * Raises the invoice for the next period of every renewing subscription that ends within INVOICE_DAYS_BEFORE days
     * and has none yet. A scheduled downgrade is billed at the plan it moves to. Returns how many were raised.
     */
    public function issueRenewalInvoices(?Carbon $at = null): int
    {
        $at ??= now();
        $raised = 0;

        $this->renewing($at)->chunkById(self::BATCH, function ($rows) use (&$raised) {
            foreach ($rows as $subscription) {
                $raised += $this->issueRenewalInvoice($subscription) ? 1 : 0;
            }
        });

        return $raised;
    }

    /**
     * Sends the 7, 3 and 1 day reminders to the owner of every renewing subscription, once per window. Returns how many were sent.
     * Each send is queued, so the mail server or SMS provider cannot hold up the scheduler.
     */
    public function sendReminders(?Carbon $at = null): int
    {
        $at ??= now();
        $sent = 0;

        $this->renewing($at)->with('shop.owner')->chunkById(self::BATCH, function ($rows) use ($at, &$sent) {
            foreach ($rows as $subscription) {
                $sent += $this->remind($subscription, $at) ? 1 : 0;
            }
        });

        return $sent;
    }

    /** Writes every shop's cached period counters to feature_usage. Returns how many shops were synced. */
    public function syncUsage(): int
    {
        $synced = 0;

        Shop::chunkById(self::BATCH, function ($shops) use (&$synced) {
            foreach ($shops as $shop) {
                $this->features->syncUsage($shop->id);
                $synced++;
            }
        });

        return $synced;
    }

    /**
     * Trial or active subscriptions that will renew and end within the reminder window.
     *
     * @return Builder<Subscription>
     */
    private function renewing(Carbon $at): Builder
    {
        return Subscription::withoutGlobalScope(ShopScope::class)
            ->whereIn('status', ['trialing', 'active'])
            ->where('auto_renew', true)
            ->whereNull('cancelled_at')
            ->whereBetween('ends_at', [$at, $at->copy()->addDays(max(self::INVOICE_DAYS_BEFORE, ...self::REMINDER_DAYS))])
            ->with('plan');
    }

    /** One invoice for the period that starts when the current one ends, unless one is already waiting. */
    private function issueRenewalInvoice(Subscription $subscription): bool
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::withoutGlobalScope(ShopScope::class)->lockForUpdate()->with(['plan', 'pendingPlan'])->findOrFail($subscription->id);

            if (! in_array($subscription->status, ['trialing', 'active'], true) || ! $subscription->auto_renew || $subscription->cancelled_at || $this->hasNextInvoice($subscription)) {
                return false;
            }

            $plan = $subscription->pendingPlan ?? $subscription->plan;
            $start = $subscription->ends_at;
            $invoice = $this->billing->createInvoice($subscription, $plan, $start, $this->billing->periodEnd($plan, $start), $plan->price_paisa);

            if ($invoice->total_paisa === 0) {
                $this->subscriptions->renew($invoice); // a free plan has nothing to pay: the period just goes on
            }

            return true;
        });
    }

    private function hasNextInvoice(Subscription $subscription): bool
    {
        return Invoice::withoutGlobalScope(ShopScope::class)
            ->where('subscription_id', $subscription->id)
            ->where('period_ends_at', '>', $subscription->ends_at)
            ->whereIn('status', ['unpaid', 'paid'])
            ->exists();
    }

    /** The largest window the days left fall in, sent once: 6 days left → the 7-day reminder, then nothing until 3. */
    private function remind(Subscription $subscription, Carbon $at): bool
    {
        $daysLeft = (int) floor($at->diffInDays($subscription->ends_at, false));
        $window = null;

        foreach (self::REMINDER_DAYS as $days) {
            if ($daysLeft <= $days) {
                $window = $days;
            }
        }

        $owner = $subscription->shop->owner;

        if ($window === null || ($subscription->last_reminder_days !== null && $subscription->last_reminder_days <= $window) || ! $owner) {
            return false;
        }

        // the write comes first: a reminder that fails to queue is retried next run, one that queued is never repeated
        $claimed = Subscription::withoutGlobalScope(ShopScope::class)->whereKey($subscription->id)
            ->where(fn (Builder $q) => $q->whereNull('last_reminder_days')->orWhere('last_reminder_days', '>', $window))
            ->update(['last_reminder_days' => $window]);

        if ($claimed === 0) {
            return false; // another run got there first
        }

        $invoice = Invoice::withoutGlobalScope(ShopScope::class)->where('subscription_id', $subscription->id)->where('status', 'unpaid')->latest('id')->first();
        $owner->setRelation('shop', $subscription->shop)->notify(new RenewalReminder($subscription, $invoice, max($daysLeft, 0)));
        Log::info('Renewal reminder sent', ['shop_id' => $subscription->shop_id, 'subscription_id' => $subscription->id, 'days' => $window]);

        return true;
    }
}
