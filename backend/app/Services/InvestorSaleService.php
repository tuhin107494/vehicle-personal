<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\InvestorSale;
use App\Models\InvestorSettlement;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Investor sales: an investor funds a customer sale on their own terms. The customer keeps paying the shop on the sale's plan;
 * the shop pays the investor back on the investor sale's own schedule, whatever the customer does.
 */
class InvestorSaleService
{
    public function create(Sale $sale, array $data, User $user): InvestorSale
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = Sale::lockForUpdate()->with('plan')->findOrFail($sale->id);

            // the investor funds what the customer still owes: an installment or a due sale, never a cash one
            if ($sale->status !== 'active' || ! in_array($sale->payment_type, ['installment', 'due'], true)) {
                throw new BusinessRuleException('An investor sale can only be added to an active installment or due sale.');
            }

            if ($sale->investor_id || $sale->plan?->investor_id) {
                throw new BusinessRuleException('This sale is already financed by an investor on its own installment plan.');
            }

            if (InvestorSale::where('sale_id', $sale->id)->where('status', 'active')->lockForUpdate()->exists()) {
                throw new BusinessRuleException('This sale already has an investor sale. Cancel it first to add another.');
            }

            if (Carbon::parse($data['date'])->lt($sale->sale_date)) {
                throw new BusinessRuleException('The investor sale date cannot be before the sale date.');
            }

            // the investor's amount: what is left after the customer's down payment, unless less is given
            $afterCustomer = Money::paisa($data['sale_price']) - Money::paisa($data['down_payment']);
            $principal = isset($data['plan_amount']) && $data['plan_amount'] !== '' ? Money::paisa($data['plan_amount']) : $afterCustomer;

            if ($principal > $afterCustomer) {
                throw new BusinessRuleException('The amount on the plan cannot be more than the '.Money::format($afterCustomer).' left after the customer\'s down payment.');
            }

            // what the investor gives the shop now; the rest of their amount they owe the shop
            $investorDown = isset($data['investor_down_payment']) && $data['investor_down_payment'] !== '' ? Money::paisa($data['investor_down_payment']) : $principal;

            if ($investorDown > $principal) {
                throw new BusinessRuleException('The investor\'s down payment cannot be more than the amount on the plan ('.Money::format($principal).').');
            }

            if (! empty($data['customer_id']) && (int) $data['customer_id'] === $sale->customer_id) {
                throw new BusinessRuleException('The additional customer must be someone other than the buyer.');
            }

            [$rate, $profit] = $this->profitOf($data, $principal);

            if ($rate > 1000) {
                throw new BusinessRuleException('The investor profit cannot be more than 1000% of the investor amount.');
            }

            $investorSale = InvestorSale::create([
                'sale_id' => $sale->id,
                'investor_id' => $data['investor_id'],
                'customer_id' => $data['customer_id'] ?? null,
                'date' => $data['date'],
                'sale_price' => $data['sale_price'],
                'down_payment' => $data['down_payment'],
                'investor_down_payment' => Money::taka($investorDown),
                'method' => $data['method'] ?? null,
                'memo_no' => $data['memo_no'] ?? null,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'principal' => Money::taka($principal),
                'profit_rate' => $rate,
                'profit_amount' => Money::taka($profit),
                'total_payable' => Money::taka($principal + $profit),
                'number_of_installments' => (int) $data['installment_count'],
                'frequency' => $data['frequency'] ?? 'monthly',
                'start_date' => $data['first_due_date'],
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            // the money the investor put in for this vehicle now (the rest of their amount comes later)
            if ($investorDown > 0) {
                InvestorSettlement::create([
                    'investor_id' => $investorSale->investor_id,
                    'investor_sale_id' => $investorSale->id,
                    'type' => 'investment',
                    'amount' => Money::taka($investorDown),
                    'date' => $investorSale->date->toDateString(),
                    'note' => "Auto: investor sale on sale #{$sale->id}",
                    'created_by' => $user->id,
                ]);
            }

            return $investorSale;
        });
    }

    /** Undoes an investor sale entered by mistake. Once the investor has been paid anything, it stays. */
    public function cancel(InvestorSale $investorSale): InvestorSale
    {
        return DB::transaction(function () use ($investorSale) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($investorSale->id);

            if ($investorSale->status !== 'active') {
                throw new BusinessRuleException('This investor sale is already cancelled.');
            }

            if ($investorSale->settlements()->whereIn('type', ['principal_return', 'profit_share'])->exists()) {
                throw new BusinessRuleException('The investor has already been paid on this investor sale, so it cannot be cancelled.');
            }

            if ($investorSale->settlements()->where('type', 'investment')->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', 'Auto:%'))->exists()) {
                throw new BusinessRuleException('The investor has paid their due on this investor sale, so it cannot be cancelled.');
            }

            $investorSale->settlements()->where('type', 'investment')->where('note', 'like', 'Auto:%')->delete();
            $investorSale->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $investorSale;
        });
    }

    /** Records a payment to the investor on an investor sale, refusing more principal or profit than is still owed. */
    public function pay(array $data, User $user): InvestorSettlement
    {
        return DB::transaction(function () use ($data, $user) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($data['investor_sale_id']);
            $this->checkPayment($investorSale, $data, null);

            return InvestorSettlement::create([
                'investor_id' => $investorSale->investor_id,
                'investor_sale_id' => $investorSale->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'date' => $data['date'],
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Edits a settlement that belongs to an investor sale, with the same checks as a new payment (its own amount left out of what was paid).
     * The investment recorded when the investor sale was made changes only by cancelling the investor sale.
     */
    public function updatePayment(InvestorSettlement $settlement, array $data): InvestorSettlement
    {
        return DB::transaction(function () use ($settlement, $data) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($settlement->investor_sale_id);
            $settlement = InvestorSettlement::lockForUpdate()->findOrFail($settlement->id);

            if ($this->madeWithSale($settlement)) {
                throw new BusinessRuleException('This investment came with the investor sale. Cancel the investor sale to change it.');
            }

            $this->checkPayment($investorSale, $data, $settlement->id);
            $settlement->update(['type' => $data['type'], 'amount' => $data['amount'], 'date' => $data['date'], 'note' => $data['note'] ?? null]);

            return $settlement;
        });
    }

    /** Deletes a settlement of an investor sale; the investment of an active investor sale goes only with the investor sale itself. */
    public function deletePayment(InvestorSettlement $settlement): void
    {
        DB::transaction(function () use ($settlement) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($settlement->investor_sale_id);

            if ($this->madeWithSale($settlement) && $investorSale->status === 'active') {
                throw new BusinessRuleException('This investment came with the investor sale. Cancel the investor sale instead.');
            }

            $settlement->delete();
        });
    }

    /** A payment must be for this investor and this active investor sale, principal or profit, and no more than is left of it. */
    private function checkPayment(InvestorSale $investorSale, array $data, ?int $exceptId): void
    {
        if ($investorSale->status !== 'active' || $investorSale->investor_id !== (int) $data['investor_id']) {
            throw new BusinessRuleException('This investor sale is not active for this investor.');
        }

        // the shop pays the investor principal and profit; the investor gives the shop the rest of their amount (an investment)
        $owed = ['principal_return' => Money::paisa($investorSale->principal), 'profit_share' => Money::paisa($investorSale->profit_amount), 'investment' => Money::paisa($investorSale->principal)];

        if (! isset($owed[$data['type']])) {
            throw new BusinessRuleException('Only principal, profit or the investor\'s due can be entered on an investor sale.');
        }

        $paid = Money::paisa($investorSale->settlements()->where('type', $data['type'])->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->sum('amount'));
        $left = $owed[$data['type']] - $paid;

        if (Money::paisa($data['amount']) > $left && $data['type'] === 'investment') {
            throw new BusinessRuleException('The investor owes only '.Money::format(max($left, 0)).' more on this investor sale.');
        }

        if (Money::paisa($data['amount']) > $left) {
            $what = $data['type'] === 'principal_return' ? 'principal' : 'profit';

            throw new BusinessRuleException('Only '.Money::format(max($left, 0))." of {$what} is left to pay on this investor sale.");
        }
    }

    /** The investment recorded when the investor sale was made: it changes only with the investor sale. */
    private function madeWithSale(InvestorSettlement $settlement): bool
    {
        return $settlement->type === 'investment' && str_starts_with((string) $settlement->note, 'Auto:');
    }

    /**
     * Where the investor sale stands today, from its schedule and what was paid: amounts in paisa.
     *
     * @return array{principal:int,profit:int,principal_paid:int,profit_paid:int,monthly:int,current_due:int,total_due:int,installments:int,installments_due:int,installments_left:int,next_date:?string}
     */
    public function position(InvestorSale $investorSale, int $principalPaid, int $profitPaid): array
    {
        $total = Money::paisa($investorSale->total_payable);
        $paid = $principalPaid + $profitPaid;
        $schedule = $investorSale->schedule();
        $dueSoFar = 0;
        $covered = 0; // paisa of the schedule that the payments cover, installment by installment
        $left = $due = 0;
        $next = null;

        foreach ($schedule as $row) {
            $come = $row['due_date']->lte(today());
            $dueSoFar += $come ? $row['amount'] : 0;
            $covered += $row['amount'];

            if ($covered > $paid) {
                $left++;
                $due += $come ? 1 : 0;
                $next ??= $row['due_date']->toDateString();
            }
        }

        return [
            'principal' => Money::paisa($investorSale->principal),
            'profit' => Money::paisa($investorSale->profit_amount),
            'principal_paid' => $principalPaid,
            'profit_paid' => $profitPaid,
            'monthly' => $schedule[0]['amount'] ?? 0,
            'current_due' => max(min($dueSoFar, $total) - $paid, 0),
            'total_due' => max($total - $paid, 0),
            'installments' => count($schedule),
            'installments_due' => $due,
            'installments_left' => $left,
            'next_date' => $next,
        ];
    }

    /** A position with its money given in taka (the counts and the date as they are). */
    public static function inTaka(array $position): array
    {
        foreach (['principal', 'profit', 'principal_paid', 'profit_paid', 'monthly', 'current_due', 'total_due'] as $key) {
            $position[$key] = Money::taka($position[$key]);
        }

        return $position;
    }

    /** @return array{0: float, 1: int} the profit rate (percent) and the profit in paisa, from either the rate or the amount given */
    private function profitOf(array $data, int $principal): array
    {
        if (isset($data['profit_amount']) && $data['profit_amount'] !== '') {
            $profit = Money::paisa($data['profit_amount']);

            return [$principal > 0 ? round($profit / $principal * 100, 2) : 0, $profit];
        }

        $rate = round((float) $data['profit_rate'], 2);

        return [$rate, Money::percent($principal, $rate)];
    }
}
