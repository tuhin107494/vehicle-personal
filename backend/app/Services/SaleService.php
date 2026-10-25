<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
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
        return DB::transaction(function () use ($data, $user) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);

            if ($vehicle->status !== 'in_stock') {
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

            if ($down > 0) {
                Payment::create([
                    'sale_id' => $sale->id,
                    'amount' => Money::taka($down),
                    'method' => $data['method'] ?? 'cash',
                    'paid_at' => Carbon::parse($data['sale_date'])->setTimeFrom(now()),
                    'note' => $type === 'cash' ? 'Full payment at sale' : 'Down payment',
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
