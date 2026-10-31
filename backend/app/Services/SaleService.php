<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Booking;
use App\Models\Draft;
use App\Models\DuePromise;
use App\Models\InstallmentPlan;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SaleService
{
    public function __construct(private PaymentService $payments) {}

    public function create(array $data, User $user): Sale
    {
        app(BookingService::class)->expireDue();

        return DB::transaction(function () use ($data, $user) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);
            $booking = ! empty($data['booking_id']) ? Booking::lockForUpdate()->findOrFail($data['booking_id']) : null;

            if ($booking && ($booking->status !== 'active' || $booking->customer_id !== (int) $data['customer_id'])) {
                throw new BusinessRuleException('This booking is no longer active, or it is for another buyer.');
            }

            // the buyer chose another vehicle than the booked one: the booked one goes back in stock and the booking follows the new one
            $changed = $booking && $booking->vehicle_id !== $vehicle->id;

            if ($changed) {
                Vehicle::lockForUpdate()->where('id', $booking->vehicle_id)->where('status', 'booked')->update(['status' => 'in_stock']);
                $booking->update(['vehicle_id' => $vehicle->id]);
            }

            // the draft this sale completes stops holding the vehicle (and goes away with the sale)
            $draft = ! empty($data['draft_id']) ? Draft::lockForUpdate()->find($data['draft_id']) : null;

            if ($draft && $draft->vehicle_id === $vehicle->id && ! $booking && $vehicle->status === 'booked') {
                $vehicle->status = 'in_stock';
            }

            // a booked vehicle can be sold only through its own booking
            if ($vehicle->status !== ($booking && ! $changed ? 'booked' : 'in_stock')) {
                throw new BusinessRuleException('Only vehicles that are in stock can be sold.');
            }

            $price = Money::paisa($data['sale_price']);
            $type = $data['payment_type'];
            $down = $type === 'cash' ? $price : Money::paisa($data['down_payment'] ?? 0);

            if ($type !== 'cash' && $down >= $price) {
                throw new BusinessRuleException('Down payment must be less than the sale price for due and installment sales.');
            }

            $sale = Sale::create([
                'vehicle_id' => $vehicle->id,
                'purchase_id' => $vehicle->current_purchase_id,
                'customer_id' => $data['customer_id'],
                'sale_date' => $data['sale_date'],
                'sale_price' => Money::taka($price),
                'down_payment' => Money::taka($down),
                'payment_type' => $type,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'memo_no' => $data['memo_no'] ?? null,
                'investor_id' => $type === 'installment' ? ($data['investor_id'] ?? null) : null,
                'created_by' => $user->id,
            ]);

            $this->settle($sale, $data, $user, $booking, $price, $down, $type);

            $vehicle->update(['status' => 'sold', 'current_sale_id' => $sale->id]);
            $draft && app(DraftService::class)->delete($draft); // the draft became this sale: it goes, and a vehicle it held is released unless this sale sold it
            $booking?->update(['status' => 'completed', 'sale_id' => $sale->id, 'closed_on' => today()]);

            return $sale;
        });
    }

    /** Records what the sale brings in: its installment plan, the payments received at the sale, and the promise to pay the rest. */
    private function settle(Sale $sale, array $data, User $user, ?Booking $booking, int $price, int $down, string $type): void
    {
        if ($type === 'installment') {
            $this->createPlan($sale, $data, $price - $down, $user);
        }

        $note = $type === 'cash' ? 'Full payment at sale' : 'Down payment';
        // money received before the sale: a booking's advance, or an advance the customer paid earlier (kept as its own payment, on its own day)
        $advance = $booking ? Money::paisa($booking->advance_amount) : (! empty($data['advance_amount']) ? Money::paisa($data['advance_amount']) : 0);

        if ($advance > 0 && $down < $advance) {
            throw new BusinessRuleException('The down payment must include the advance of '.Money::format($advance).'.');
        }

        if (! $booking && $advance >= $price) {
            throw new BusinessRuleException('The advance must be less than the sale price.');
        }

        // the advance was received earlier (a booking's on its day); whatever is paid on top is received at the sale
        if ($advance > 0) {
            Payment::create([
                'sale_id' => $sale->id,
                'amount' => Money::taka($advance),
                'method' => $booking ? $booking->advance_method : ($data['method'] ?? 'cash'),
                'paid_at' => $booking ? $booking->created_at : Carbon::parse($data['advance_date'] ?? $data['sale_date'])->setTimeFrom(now()),
                'note' => $booking ? $note : 'Advance', // not "Down payment": the daily report counts it as cash on the day it came
                'received_by' => $user->id,
            ]);
        }

        if ($down - $advance > 0) {
            Payment::create([
                'sale_id' => $sale->id,
                'amount' => Money::taka($down - $advance),
                'method' => $data['method'] ?? 'cash',
                'paid_at' => Carbon::parse($data['sale_date'])->setTimeFrom(now()),
                'note' => $note,
                'received_by' => $user->id,
            ]);
        }

        $this->payments->recalculate($sale);

        if ($type === 'due' && ! empty($data['promised_date'])) {
            DuePromise::create([
                'sale_id' => $sale->id,
                'promised_date' => $data['promised_date'],
                'promised_amount' => $sale->due_amount,
                'note' => $data['promise_note'] ?? null,
                'created_by' => $user->id,
            ]);
        }
    }

    /** Payments made when the sale was recorded; anything else was collected later and ties the money terms down. */
    private const SALE_TIME_NOTES = ['Full payment at sale', 'Down payment', 'Advance'];

    /**
     * Edits a sale and keeps a history of what changed. The buyer, dates, memo number and guarantor can always change.
     * The money terms (price, down payment, type, installments, promise) are rebuilt, which is only safe before any later collection.
     */
    public function update(Sale $sale, array $data, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::lockForUpdate()->with(['plan.installments', 'payments', 'promises', 'booking'])->findOrFail($sale->id);

            if ($sale->status !== 'active') {
                throw new BusinessRuleException('Only active sales can be edited.');
            }

            $before = $this->snapshot($sale);
            $type = $data['payment_type'];
            $price = Money::paisa($data['sale_price']);
            $down = $type === 'cash' ? $price : Money::paisa($data['down_payment'] ?? 0);
            $booking = $sale->booking;
            $dateChanged = $sale->sale_date->toDateString() !== Carbon::parse($data['sale_date'])->toDateString();

            $sale->update([
                'customer_id' => $data['customer_id'],
                'sale_date' => $data['sale_date'],
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'memo_no' => $data['memo_no'] ?? null,
            ]);

            if ($this->moneyTermsChanged($sale, $data, $price, $down, $type)) {
                $this->rebuildMoneyTerms($sale, $data, $user, $booking, $price, $down, $type);
            } elseif ($dateChanged) {
                // the payments taken at the sale follow its date
                foreach ($sale->payments->whereIn('note', ['Full payment at sale', 'Down payment']) as $payment) {
                    $payment->update(['paid_at' => Carbon::parse($data['sale_date'])->setTimeFrom($payment->paid_at)]);
                }

                if ($sale->plan) {
                    InvestorSettlement::where('plan_id', $sale->plan->id)->where('type', 'investment')->where('note', 'like', 'Auto:%')->update(['date' => $sale->sale_date]);
                }
            }

            $sale = Sale::with(['plan.installments', 'payments', 'promises', 'customer', 'investor'])->findOrFail($sale->id);
            $changes = $this->diff($before, $this->snapshot($sale));

            if ($changes) {
                $sale->edits()->create(['user_id' => $user->id, 'changes' => $changes]);
            }

            return $sale;
        });
    }

    /** Whether the price, down payment, type, installment plan or promise differ from what the sale holds now. */
    private function moneyTermsChanged(Sale $sale, array $data, int $price, int $down, string $type): bool
    {
        if (Money::paisa($sale->sale_price) !== $price || Money::paisa($sale->down_payment) !== $down || $sale->payment_type !== $type) {
            return true;
        }

        if ($type === 'installment') {
            $plan = $sale->plan;
            [, $interest] = $this->interestOf($data, $price - $down);

            return ! $plan
                || $plan->number_of_installments !== (int) $data['installment_count']
                || $plan->frequency !== ($data['frequency'] ?? 'monthly')
                || $plan->start_date->toDateString() !== Carbon::parse($data['first_due_date'])->toDateString()
                || Money::paisa($plan->interest_amount) !== $interest
                || $plan->investor_id !== (empty($data['investor_id']) ? null : (int) $data['investor_id'])
                || (! empty($data['investor_id']) && round((float) $plan->investor_share_percent, 2) !== round((float) ($data['investor_share_percent'] ?? 0), 2));
        }

        if ($type === 'due') {
            $promise = $sale->promises->sortBy('id')->first();

            return ($promise?->promised_date?->toDateString()) !== (empty($data['promised_date']) ? null : Carbon::parse($data['promised_date'])->toDateString())
                || ($promise?->note) !== ($data['promise_note'] ?? null);
        }

        return false;
    }

    private function rebuildMoneyTerms(Sale $sale, array $data, User $user, ?Booking $booking, int $price, int $down, string $type): void
    {
        $collected = $sale->payments->contains(fn (Payment $p) => $p->installment_id !== null || $p->method === 'adjustment' || ! in_array($p->note, self::SALE_TIME_NOTES, true));

        if ($collected) {
            throw new BusinessRuleException('Payments have been collected on this sale, so its price, payment type and installments cannot be changed. Cancel the sale and enter it again.');
        }

        $plan = $sale->plan;

        if ($plan && InvestorSettlement::where('plan_id', $plan->id)->where(fn ($q) => $q->where('type', '!=', 'investment')->orWhere('note', 'not like', 'Auto:%'))->exists()) {
            throw new BusinessRuleException('Settlements have been recorded against this sale\'s financing, so its terms cannot be changed.');
        }

        // an advance paid before the sale stays as it was (a booking's comes from the booking)
        $advance = $sale->payments->firstWhere('note', 'Advance');

        if ($advance) {
            $data['advance_amount'] = $advance->amount;
            $data['advance_date'] = $advance->paid_at->toDateString();
        }

        $sale->payments()->delete();
        $sale->promises()->delete();

        if ($plan) {
            InvestorSettlement::where('plan_id', $plan->id)->delete();
            $plan->installments()->delete();
            $plan->delete();
        }

        $sale->update([
            'sale_price' => Money::taka($price),
            'down_payment' => Money::taka($down),
            'payment_type' => $type,
            'investor_id' => $type === 'installment' ? ($data['investor_id'] ?? null) : null,
        ]);

        $this->settle($sale, $data, $user, $booking, $price, $down, $type);
    }

    /** @return array<string,string> what a person would want to see changed, by label */
    private function snapshot(Sale $sale): array
    {
        $sale->loadMissing(['customer', 'investor', 'plan', 'promises']);
        $plan = $sale->plan;
        $promise = $sale->promises->sortBy('id')->first();
        $money = fn ($v) => number_format((float) $v, 2, '.', '');

        return array_filter([
            'Sale date' => $sale->sale_date->toDateString(),
            'Buyer' => $sale->customer?->name,
            'Sale price' => $money($sale->sale_price),
            'Down payment' => $money($sale->down_payment),
            'Payment type' => $sale->payment_type,
            'Installments' => $plan ? (string) $plan->number_of_installments : null,
            'First installment' => $plan?->start_date->toDateString(),
            'Frequency' => $plan?->frequency,
            'Interest' => $plan ? $money($plan->interest_amount) : null,
            'Investor' => $plan ? $sale->investor?->name : null,
            'Promise date' => $promise?->promised_date?->toDateString(),
            'Memo no.' => $sale->memo_no,
            'Guarantor name' => $sale->guarantor_name,
            'Guarantor mobile' => $sale->guarantor_phone,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return list<array{field:string,from:?string,to:?string}> */
    private function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $changes[] = ['field' => $field, 'from' => $before[$field] ?? null, 'to' => $after[$field] ?? null];
            }
        }

        return $changes;
    }

    public function cancel(Sale $sale, string $reason, float|string $refund, User $user): Sale
    {
        return DB::transaction(function () use ($sale, $reason, $refund, $user) {
            $sale = Sale::lockForUpdate()->findOrFail($sale->id);
            $refund = Money::paisa($refund);

            if ($sale->status !== 'active') {
                throw new BusinessRuleException('Only active sales can be cancelled.');
            }

            if ($refund > Money::paisa($sale->total_paid)) {
                throw new BusinessRuleException('Refund cannot be more than the amount already paid.');
            }

            if ($refund > 0) {
                Payment::create([
                    'sale_id' => $sale->id,
                    'amount' => Money::taka(-$refund),
                    'method' => 'cash',
                    'paid_at' => now(),
                    'received_by' => $user->id,
                    'note' => 'Refund on cancellation',
                ]);
            }

            $sale->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $this->payments->recalculate($sale);
            $sale->update(['due_amount' => 0]);

            $sale->vehicle->update(['status' => 'in_stock', 'current_sale_id' => null]);

            if ($sale->plan) {
                $sale->plan->installments()->whereIn('status', ['pending', 'partial', 'overdue'])->update(['status' => 'cancelled']);
                $this->returnInvestorPrincipal($sale, $user);
            }

            $sale->promises()->where('status', 'open')->update(['status' => 'cancelled']);

            return $sale;
        });
    }

    /** The financing ends with the sale: the investor gets back the part of the principal not yet returned from collections. */
    private function returnInvestorPrincipal(Sale $sale, User $user): void
    {
        $plan = $sale->plan;

        if (! $plan->investor_id) {
            return;
        }

        $returned = Money::paisa(InvestorSettlement::where('plan_id', $plan->id)->where('type', 'principal_return')->sum('amount'));
        $left = Money::paisa($plan->principal) - $returned;

        if ($left <= 0) {
            return;
        }

        InvestorSettlement::create([
            'investor_id' => $plan->investor_id,
            'plan_id' => $plan->id,
            'type' => 'principal_return',
            'amount' => Money::taka($left),
            'date' => today()->toDateString(),
            'note' => "Auto: sale #{$sale->id} cancelled, principal returned",
            'created_by' => $user->id,
        ]);
    }

    /** @return array{0: float|int, 1: int} the interest rate (percent) and the interest in paisa, from either the rate or the amount given */
    private function interestOf(array $data, int $principal): array
    {
        if (isset($data['interest_amount']) && $data['interest_amount'] !== '') {
            $interest = Money::paisa($data['interest_amount']);

            return [$principal > 0 ? round($interest / $principal * 100, 2) : 0, $interest];
        }

        $rate = round((float) ($data['interest_rate'] ?? 0), 2);

        return [$rate, Money::percent($principal, $rate)];
    }

    /** @param  int  $principal  what is financed (sale price less down payment), in paisa */
    private function createPlan(Sale $sale, array $data, int $principal, User $user): void
    {
        $count = (int) $data['installment_count'];
        $frequency = $data['frequency'] ?? 'monthly';
        [$rate, $interest] = $this->interestOf($data, $principal);
        $total = $principal + $interest;
        $start = Carbon::parse($data['first_due_date']);

        $plan = InstallmentPlan::create([
            'sale_id' => $sale->id,
            'investor_id' => $data['investor_id'] ?? null,
            'principal' => Money::taka($principal),
            'interest_rate' => $rate,
            'interest_amount' => Money::taka($interest),
            'total_payable' => Money::taka($total),
            'number_of_installments' => $count,
            'frequency' => $frequency,
            'start_date' => $start->toDateString(),
            'investor_share_percent' => ($data['investor_id'] ?? null) ? ($data['investor_share_percent'] ?? 0) : 0,
        ]);

        foreach (Money::split($total, $count) as $i => $amount) {
            $due = $frequency === 'weekly' ? $start->copy()->addWeeks($i) : $start->copy()->addMonthsNoOverflow($i);

            $plan->installments()->create([
                'seq_no' => $i + 1,
                'due_date' => $due->toDateString(),
                'amount' => Money::taka($amount),
            ]);
        }

        if (empty($data['investor_id'])) {
            return; // the shop finances this plan itself
        }

        InvestorSettlement::create([
            'investor_id' => $data['investor_id'],
            'plan_id' => $plan->id,
            'type' => 'investment',
            'amount' => Money::taka($principal),
            'date' => $sale->sale_date,
            'note' => "Auto: financing for sale #{$sale->id}",
            'created_by' => $user->id,
        ]);
    }
}
