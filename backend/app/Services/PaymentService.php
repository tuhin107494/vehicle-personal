<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Installment;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    /** @return array<int,Payment> payments created (one per installment touched) */
    public function collect(Sale $sale, array $data, User $user): array
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::lockForUpdate()->with('plan.installments')->findOrFail($sale->id);

            if ($sale->status !== 'active') {
                throw new BusinessRuleException('Payments cannot be collected on a cancelled sale.');
            }

            $amount = round((float) $data['amount'], 2);

            if ($sale->due_amount <= 0) {
                throw new BusinessRuleException('This sale is already fully paid.');
            }

            if ($amount > $sale->due_amount) {
                throw new BusinessRuleException('Payment of '.number_format($amount, 2).' is more than the amount due ('.number_format($sale->due_amount, 2).').');
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            $base = [
                'sale_id' => $sale->id,
                'method' => $data['method'] ?? 'cash',
                'paid_at' => $paidAt,
                'reference' => $data['reference'] ?? null,
                'received_by' => $user->id,
                'note' => $data['note'] ?? null,
            ];

            $payments = [];

            if ($sale->payment_type === 'installment') {
                foreach ($this->allocate($sale, $amount, $data['installment_id'] ?? null) as [$installment, $applied]) {
                    $installment->paid_amount = round($installment->paid_amount + $applied, 2);
                    $this->refreshInstallment($installment, $paidAt);
                    $payments[] = Payment::create($base + ['installment_id' => $installment->id, 'amount' => $applied]);
                }

                $this->creditInvestor($sale, $amount, $paidAt, $user);
            } else {
                $payments[] = Payment::create($base + ['amount' => $amount]);
            }

            $this->recalculate($sale);

            if ($sale->payment_type === 'due') {
                $this->settlePromises($sale, $amount, $paidAt);
            }

            return $payments;
        });
    }

    /** Recompute total_paid, due_amount and payment_status from the payments ledger. */
    public function recalculate(Sale $sale): Sale
    {
        $sale->loadMissing('plan');
        $paid = round((float) $sale->payments()->sum('amount'), 2);
        $due = round(max($sale->grandTotal() - $paid, 0), 2);

        $sale->update([
            'total_paid' => $paid,
            'due_amount' => $due,
            'payment_status' => $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);

        return $sale;
    }

    /** @return array<int,array{0:Installment,1:float}> */
    private function allocate(Sale $sale, float $amount, ?int $preferredId): array
    {
        $installments = $sale->plan->installments
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->sortBy('seq_no')
            ->values();

        if ($preferredId) {
            $preferred = $installments->firstWhere('id', $preferredId);

            if (! $preferred) {
                throw new BusinessRuleException('The selected installment does not belong to this sale or is already settled.');
            }

            $installments = $installments->reject(fn ($i) => $i->id === $preferredId)->prepend($preferred)->values();
        }

        $left = $amount;
        $allocation = [];

        foreach ($installments as $installment) {
            if ($left <= 0) {
                break;
            }

            $applied = round(min($left, $installment->remaining()), 2);

            if ($applied > 0) {
                $allocation[] = [$installment, $applied];
                $left = round($left - $applied, 2);
            }
        }

        return $allocation;
    }

    private function refreshInstallment(Installment $installment, Carbon $paidAt): void
    {
        if ($installment->paid_amount >= $installment->amount) {
            $installment->status = 'paid';
            $installment->paid_at = $paidAt;
        } elseif ($installment->due_date->lt(today())) {
            $installment->status = 'overdue';
        } else {
            $installment->status = $installment->paid_amount > 0 ? 'partial' : 'pending';
        }

        $installment->save();
    }

    private function creditInvestor(Sale $sale, float $amount, Carbon $paidAt, User $user): void
    {
        $plan = $sale->plan;

        if (! $plan->investor_id) {
            return;
        }

        $principalPart = round($amount * $plan->principal / $plan->total_payable, 2);
        $interestPart = round($amount - $principalPart, 2);
        $profit = round($interestPart * $plan->investor_share_percent / 100, 2);

        foreach (['principal_return' => $principalPart, 'profit_share' => $profit] as $type => $value) {
            if ($value > 0) {
                InvestorSettlement::create([
                    'investor_id' => $plan->investor_id,
                    'plan_id' => $plan->id,
                    'type' => $type,
                    'amount' => $value,
                    'date' => $paidAt->toDateString(),
                    'note' => "Auto: collection on sale #{$sale->id}",
                    'created_by' => $user->id,
                ]);
            }
        }
    }

    private function settlePromises(Sale $sale, float $amount, Carbon $paidAt): void
    {
        $cleared = $sale->due_amount <= 0;
        $promise = $sale->promises()->where('status', 'open')->orderBy('promised_date')->first();

        if ($promise && ($cleared || $amount >= $promise->promised_amount)) {
            $promise->update(['status' => $paidAt->toDateString() <= $promise->promised_date->toDateString() ? 'kept' : 'broken']);
        }

        if ($cleared) {
            $sale->promises()->where('status', 'open')->update(['status' => 'cancelled']);
        }
    }
}
