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
                'investor_id' => $type === 'installment' ? ($data['investor_id'] ?? null) : null,
                'created_by' => $user->id,
            ]);

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

            $vehicle->update(['status' => 'sold', 'current_sale_id' => $sale->id]);
            $draft && app(DraftService::class)->delete($draft); // the draft became this sale: it goes, and a vehicle it held is released unless this sale sold it
            $booking?->update(['status' => 'completed', 'sale_id' => $sale->id, 'closed_on' => today()]);

            return $sale;
        });
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

    /** @param  int  $principal  what is financed (sale price less down payment), in paisa */
    private function createPlan(Sale $sale, array $data, int $principal, User $user): void
    {
        $count = (int) $data['installment_count'];
        $frequency = $data['frequency'] ?? 'monthly';
        if (isset($data['interest_amount']) && $data['interest_amount'] !== '') {
            $interest = Money::paisa($data['interest_amount']);
            $rate = $principal > 0 ? round($interest / $principal * 100, 2) : 0;
        } else {
            $rate = round((float) ($data['interest_rate'] ?? 0), 2);
            $interest = Money::percent($principal, $rate);
        }
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
