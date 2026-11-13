<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\Coupon;
use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Scopes\ShopScope;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Invoices, coupons and proration. All amounts are whole paisa. Callers wrap these in their own transaction. */
class BillingService
{
    public const INVOICE_DUE_DAYS = 7;

    /** An unpaid invoice for one period of a plan. Returns null when there is nothing to pay (a free plan or a full discount is still an invoice at 0, recorded for the history). */
    public function createInvoice(Subscription $subscription, Plan $plan, Carbon $periodStart, Carbon $periodEnd, int $amountPaisa, ?Coupon $coupon = null): Invoice
    {
        $discount = $coupon ? $this->applyCoupon($coupon, $amountPaisa) : 0;

        $invoice = Invoice::create([
            'shop_id' => $subscription->shop_id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'coupon_id' => $coupon?->id,
            'invoice_no' => $this->nextInvoiceNo(),
            'amount_paisa' => $amountPaisa,
            'discount_paisa' => $discount,
            'total_paisa' => max($amountPaisa - $discount, 0),
            'currency' => $plan->currency,
            'status' => 'unpaid',
            'period_starts_at' => $periodStart,
            'period_ends_at' => $periodEnd,
            'due_date' => today()->addDays(self::INVOICE_DUE_DAYS),
        ]);

        if ($coupon) {
            $coupon->increment('used_count');
        }

        return $invoice;
    }

    /** The coupon behind a code a shop typed, or a 422 on the field when there is no such usable coupon. */
    public function findCoupon(?string $code): ?Coupon
    {
        if (blank($code)) {
            return null;
        }

        $coupon = Coupon::whereRaw('LOWER(code) = ?', [strtolower(trim((string) $code))])->first();

        if (! $coupon?->isRedeemable()) {
            throw ValidationException::withMessages(['coupon_code' => 'This coupon code is not valid.']);
        }

        return $coupon;
    }

    /** The discount (paisa) a coupon takes off an amount, never more than the amount. Refuses a coupon that cannot be used. */
    public function applyCoupon(Coupon $coupon, int $amountPaisa): int
    {
        if (! $coupon->isRedeemable()) {
            throw new BusinessRuleException('This coupon is no longer valid.');
        }

        $discount = $coupon->type === 'percent' ? Money::percent($amountPaisa, $coupon->value) : $coupon->value;

        return min($discount, $amountPaisa);
    }

    /**
     * What a shop owes now to move to a dearer plan for the rest of the current period: the new plan's price for the
     * time left, minus the unused part of what was paid for the current plan. Never negative.
     */
    public function calculateProration(Subscription $subscription, Plan $to, ?Carbon $at = null): int
    {
        $at ??= now();
        $period = max($subscription->starts_at->diffInSeconds($subscription->ends_at), 1);
        $remaining = max($at->diffInSeconds($subscription->ends_at, false), 0);

        // what the current plan cost for the period; a trial has paid nothing, so nothing comes back
        $paidPrice = $subscription->status === 'trialing' ? 0 : $subscription->plan->price_paisa;
        $credit = Money::share($paidPrice, $remaining, $period);
        $charge = Money::share($to->price_paisa, $remaining, $period);

        return max($charge - $credit, 0);
    }

    /** When a period that starts at $from ends for a plan. */
    public function periodEnd(Plan $plan, Carbon $from): Carbon
    {
        return $from->copy()->addMonthsNoOverflow($plan->periodMonths());
    }

    /** INV-202611-000042: the month and a running number across all shops, so it is unique and sorts by time. */
    private function nextInvoiceNo(): string
    {
        $seq = (int) Invoice::withoutGlobalScope(ShopScope::class)->lockForUpdate()->max('id') + 1;

        return sprintf('INV-%s-%06d', now()->format('Ym'), $seq);
    }
}
