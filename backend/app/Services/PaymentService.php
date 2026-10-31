<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Installment;
use App\Models\InvestorSettlement;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleCharge;
use App\Models\User;
use App\Support\Money;
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

            $amount = Money::paisa($data['amount']);
            $due = Money::paisa($sale->due_amount);

            if ($due <= 0) {
                throw new BusinessRuleException('This sale is already fully paid.');
            }

            if ($amount > $due) {
                throw new BusinessRuleException('Payment of '.Money::format($amount).' is more than the amount due ('.Money::format($due).').');
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now();
            $base = [
                'sale_id' => $sale->id,
                'method' => $data['method'] ?? 'cash',
                'paid_at' => $paidAt,
                'reference' => $data['reference'] ?? null,
                'memo_no' => $data['memo_no'] ?? null,
                'received_by' => $user->id,
                'note' => $data['note'] ?? null,
            ];

            $payments = [];
            $toCharges = $amount; // what is left for the charges once the sale's own balance is paid

            if ($sale->payment_type === 'installment') {
                $applied = 0;

                foreach ($this->allocate($sale, $amount, $data['installment_id'] ?? null) as [$installment, $part]) {
                    $installment->paid_amount = Money::taka(Money::paisa($installment->paid_amount) + $part);
                    $this->refreshInstallment($installment, $paidAt);
                    $payments[] = Payment::create($base + ['installment_id' => $installment->id, 'amount' => Money::taka($part)]);
                    $applied += $part;
                }

                $applied > 0 && $this->creditInvestor($sale, $applied, $paidAt, $user);
                $toCharges = $amount - $applied;
            } else {
                $toBase = min($amount, max($due - $this->unpaidCharges($sale), 0));
                $toBase > 0 && $payments[] = Payment::create($base + ['amount' => Money::taka($toBase)]);
                $toCharges = $amount - $toBase;
            }

            if ($toCharges > 0) {
                array_push($payments, ...$this->payCharges($sale, $toCharges, $base));
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
        $paid = Money::paisa($sale->payments()->sum('amount'));
        $due = max(Money::paisa($sale->grandTotal()) - $paid, 0);

        $sale->update([
            'total_paid' => Money::taka($paid),
            'due_amount' => Money::taka($due),
            'payment_status' => $due <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);

        return $sale;
    }

    /** What the customer still owes on the sale's charges, in paisa. */
    public function unpaidCharges(Sale $sale): int
    {
        $charged = Money::paisa(SaleCharge::where('sale_id', $sale->id)->sum('amount'));
        $paid = Money::paisa(Payment::where('sale_id', $sale->id)->whereNotNull('sale_charge_id')->sum('amount'));

        return max($charged - $paid, 0);
    }

    /**
     * Puts a payment (in paisa) against the sale's charges, oldest first.
     *
     * @return array<int,Payment>
     */
    private function payCharges(Sale $sale, int $amount, array $base): array
    {
        $payments = [];

        foreach (SaleCharge::where('sale_id', $sale->id)->orderBy('charged_on')->orderBy('id')->get() as $charge) {
            if ($amount <= 0) {
                break;
            }

            $owed = Money::paisa($charge->amount) - Money::paisa(Payment::where('sale_charge_id', $charge->id)->sum('amount'));
            $take = min($amount, $owed);

            if ($take > 0) {
                $payments[] = Payment::create($base + ['sale_charge_id' => $charge->id, 'amount' => Money::taka($take)]);
                $amount -= $take;
            }
        }

        return $payments;
    }

    /**
     * Spreads a payment (in paisa) over the unpaid installments, the chosen one first, then oldest first.
     *
     * @return array<int,array{0:Installment,1:int}> each installment with the paisa applied to it
     */
    private function allocate(Sale $sale, int $amount, ?int $preferredId): array
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

            $applied = min($left, Money::paisa($installment->remaining()));

            if ($applied > 0) {
                $allocation[] = [$installment, $applied];
                $left -= $applied;
            }
        }

        return $allocation;
    }

    public function refreshInstallment(Installment $installment, Carbon $paidAt): void
    {
        if (Money::paisa($installment->paid_amount) >= Money::paisa($installment->amount)) {
            $installment->status = 'paid';
            $installment->paid_at = $paidAt;
        } elseif ($installment->due_date->lt(today())) {
            $installment->status = 'overdue';
        } else {
            $installment->status = $installment->paid_amount > 0 ? 'partial' : 'pending';
        }

        $installment->save();
    }

    /** The investor's part of a collection (in paisa): the principal it pays back, and their share of the interest in it. */
    private function creditInvestor(Sale $sale, int $amount, Carbon $paidAt, User $user): void
    {
        $plan = $sale->plan;

        if (! $plan->investor_id) {
            return;
        }

        $principalPart = Money::share($amount, $plan->principal, $plan->total_payable);
        $profit = Money::percent($amount - $principalPart, $plan->investor_share_percent);

        foreach (['principal_return' => $principalPart, 'profit_share' => $profit] as $type => $value) {
            if ($value > 0) {
                InvestorSettlement::create([
                    'investor_id' => $plan->investor_id,
                    'plan_id' => $plan->id,
                    'type' => $type,
                    'amount' => Money::taka($value),
                    'date' => $paidAt->toDateString(),
                    'note' => "Auto: collection on sale #{$sale->id}",
                    'created_by' => $user->id,
                ]);
            }
        }
    }

    /** @param  int  $amount  the payment, in paisa */
    private function settlePromises(Sale $sale, int $amount, Carbon $paidAt): void
    {
        $cleared = Money::paisa($sale->due_amount) <= 0;
        $promise = $sale->promises()->where('status', 'open')->orderBy('promised_date')->first();

        if ($promise && ($cleared || $amount >= Money::paisa($promise->promised_amount))) {
            $promise->update(['status' => $paidAt->toDateString() <= $promise->promised_date->toDateString() ? 'kept' : 'broken']);
        }

        if ($cleared) {
            $sale->promises()->where('status', 'open')->update(['status' => 'cancelled']);
        }
    }
}
