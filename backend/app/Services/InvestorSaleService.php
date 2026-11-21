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
    public function __construct(private PaymentService $payments) {}

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

            // what the investor gives the shop now toward the price (not tied to the amount on the plan); the rest of the price they owe the shop
            $investorDown = isset($data['investor_down_payment']) && $data['investor_down_payment'] !== '' ? Money::paisa($data['investor_down_payment']) : $afterCustomer;

            if ($investorDown > $afterCustomer) {
                throw new BusinessRuleException('The investor\'s down payment cannot be more than the '.Money::format($afterCustomer).' left after the customer\'s down payment.');
            }

            if (! empty($data['customer_id']) && (int) $data['customer_id'] === $sale->customer_id) {
                throw new BusinessRuleException('The additional customer must be someone other than the buyer.');
            }

            [$rate, $profit] = $this->profitOf($data, $principal);

            if ($rate > 1000) {
                throw new BusinessRuleException('The investor profit cannot be more than 1000% a year of the amount on the plan.');
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
                // with no plan (amount 0) there is no schedule: no installments, and it starts on its own date
                'number_of_installments' => (int) ($data['installment_count'] ?? 0),
                'frequency' => $data['frequency'] ?? 'monthly',
                'start_date' => $data['first_due_date'] ?? $data['date'],
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

            $this->syncInstallments($investorSale);

            return $investorSale;
        });
    }

    /**
     * Edits an investor sale. Its details (date, memo, guarantor, additional customer) can always change. Its money terms
     * (price, down payments, amount on the plan, profit, schedule) only while no money has moved on it since it was made:
     * the shop has paid the investor nothing and the investor has given nothing beyond their down payment.
     * Changed terms redraw the schedule and the investor's down payment entry.
     */
    public function update(InvestorSale $investorSale, array $data): InvestorSale
    {
        return DB::transaction(function () use ($investorSale, $data) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($investorSale->id);
            $sale = Sale::findOrFail($investorSale->sale_id);

            if ($investorSale->status !== 'active') {
                throw new BusinessRuleException('A cancelled investor sale cannot be edited.');
            }

            if (Carbon::parse($data['date'])->lt($sale->sale_date)) {
                throw new BusinessRuleException('The investor sale date cannot be before the sale date.');
            }

            if (! empty($data['customer_id']) && (int) $data['customer_id'] === $sale->customer_id) {
                throw new BusinessRuleException('The additional customer must be someone other than the buyer.');
            }

            $afterCustomer = Money::paisa($data['sale_price']) - Money::paisa($data['down_payment']);
            $principal = isset($data['plan_amount']) && $data['plan_amount'] !== '' ? Money::paisa($data['plan_amount']) : $afterCustomer;
            $investorDown = isset($data['investor_down_payment']) && $data['investor_down_payment'] !== '' ? Money::paisa($data['investor_down_payment']) : $afterCustomer;
            [$rate, $profit] = $this->profitOf($data, $principal);

            // the same percentage on the same amount keeps the profit as saved: a profit first given as an amount is stored
            // with its rate rounded to 2 decimals, and working it out again from that rate could move it by a few paisa
            if ($principal === Money::paisa($investorSale->principal) && ! isset($data['profit_amount']) && round($rate, 2) === round((float) $investorSale->profit_rate, 2)
                && (int) ($data['installment_count'] ?? 0) === $investorSale->number_of_installments && ($data['frequency'] ?? 'monthly') === $investorSale->frequency) {
                $profit = Money::paisa($investorSale->profit_amount);
            }

            $terms = [
                'sale_price' => Money::paisa($data['sale_price']), 'down_payment' => Money::paisa($data['down_payment']),
                'investor_down_payment' => $investorDown, 'principal' => $principal, 'profit_amount' => $profit,
                'number_of_installments' => (int) ($data['installment_count'] ?? 0), 'frequency' => $data['frequency'] ?? 'monthly',
                'start_date' => Carbon::parse($data['first_due_date'] ?? $data['date'])->toDateString(),
            ];
            $now = [
                'sale_price' => Money::paisa($investorSale->sale_price), 'down_payment' => Money::paisa($investorSale->down_payment),
                'investor_down_payment' => $investorSale->investor_down_payment !== null ? Money::paisa($investorSale->investor_down_payment) : $investorSale->investorAmount(), 'principal' => Money::paisa($investorSale->principal),
                'profit_amount' => Money::paisa($investorSale->profit_amount), 'number_of_installments' => $investorSale->number_of_installments,
                'frequency' => $investorSale->frequency, 'start_date' => $investorSale->start_date->toDateString(),
            ];
            $termsChanged = $terms != $now;

            if ($termsChanged) {
                $moneyMoved = $investorSale->settlements()->whereIn('type', ['principal_return', 'profit_share'])->exists()
                    || $investorSale->settlements()->where('type', 'investment')->where(fn ($q) => $q->whereNull('note')->orWhere('note', 'not like', 'Auto:%'))->exists();

                if ($moneyMoved) {
                    throw new BusinessRuleException('Money has already been paid on this investor sale, so its amounts and schedule cannot change. Only its details can be edited.');
                }

                if ($principal > $afterCustomer) {
                    throw new BusinessRuleException('The amount on the plan cannot be more than the '.Money::format($afterCustomer).' left after the customer\'s down payment.');
                }

                if ($investorDown > $afterCustomer) {
                    throw new BusinessRuleException('The investor\'s down payment cannot be more than the '.Money::format($afterCustomer).' left after the customer\'s down payment.');
                }

                if ($rate > 1000) {
                    throw new BusinessRuleException('The investor profit cannot be more than 1000% a year of the amount on the plan.');
                }
            }

            $investorSale->update([
                'customer_id' => $data['customer_id'] ?? null,
                'date' => $data['date'],
                'method' => $data['method'] ?? null,
                'memo_no' => $data['memo_no'] ?? null,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                ...($termsChanged ? [
                    'sale_price' => $data['sale_price'],
                    'down_payment' => $data['down_payment'],
                    'investor_down_payment' => Money::taka($investorDown),
                    'principal' => Money::taka($principal),
                    'profit_rate' => $rate,
                    'profit_amount' => Money::taka($profit),
                    'total_payable' => Money::taka($principal + $profit),
                    'number_of_installments' => $terms['number_of_installments'],
                    'frequency' => $terms['frequency'],
                    'start_date' => $terms['start_date'],
                ] : []),
            ]);

            // the investor's down payment entry follows the edit (its amount, and its date with the investor sale's date)
            $auto = $investorSale->settlements()->where('type', 'investment')->where('note', 'like', 'Auto:%')->first();
            $down = $investorSale->investor_down_payment !== null ? Money::paisa($investorSale->investor_down_payment) : $investorSale->investorAmount();

            if ($down > 0 && $auto) {
                $auto->update(['amount' => Money::taka($down), 'date' => $investorSale->date->toDateString()]);
            } elseif ($down > 0) {
                InvestorSettlement::create([
                    'investor_id' => $investorSale->investor_id, 'investor_sale_id' => $investorSale->id, 'type' => 'investment',
                    'amount' => Money::taka($down), 'date' => $investorSale->date->toDateString(),
                    'note' => "Auto: investor sale on sale #{$sale->id}", 'created_by' => $investorSale->created_by,
                ]);
            } elseif ($auto) {
                $auto->delete();
            }

            // a new schedule: its installments are drawn again
            if ($termsChanged) {
                $investorSale->installments()->delete();
                $this->syncInstallments($investorSale);
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
            $this->syncInstallments($investorSale);

            return $investorSale;
        });
    }

    /** Records a payment to the investor on an investor sale, refusing more principal or profit than is still owed. */
    public function pay(array $data, User $user): InvestorSettlement
    {
        return DB::transaction(function () use ($data, $user) {
            $investorSale = InvestorSale::lockForUpdate()->findOrFail($data['investor_sale_id']);
            $this->checkPayment($investorSale, $data, null);

            $settlement = InvestorSettlement::create([
                'investor_id' => $investorSale->investor_id,
                'investor_sale_id' => $investorSale->id,
                'type' => $data['type'],
                'amount' => $data['amount'],
                'date' => $data['date'],
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);
            $this->syncInstallments($investorSale);

            return $settlement;
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
            $this->syncInstallments($investorSale);

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
            $this->syncInstallments($investorSale);
        });
    }

    /**
     * Keeps the investor sale's stored installments (in the installments table, beside the customers') in step: made from its
     * schedule the first time, then what the shop has paid the investor (principal and profit) spread over them in order.
     * A cancelled investor sale's unpaid installments are cancelled.
     */
    public function syncInstallments(InvestorSale $investorSale): void
    {
        if (! $investorSale->installments()->exists()) {
            foreach ($investorSale->schedule() as $row) {
                $investorSale->installments()->create([
                    'shop_id' => $investorSale->shop_id,
                    'seq_no' => $row['seq_no'],
                    'due_date' => $row['due_date']->toDateString(),
                    'amount' => Money::taka($row['amount']),
                ]);
            }
        }

        $settlements = $investorSale->settlements()->whereIn('type', ['principal_return', 'profit_share']);
        $left = Money::paisa((clone $settlements)->sum('amount'));
        $lastPaid = Carbon::parse((clone $settlements)->max('date') ?? today());

        foreach ($investorSale->installments()->get() as $installment) {
            $part = min($left, Money::paisa($installment->amount));
            $left -= $part;
            $installment->paid_amount = Money::taka($part);
            $installment->paid_at = null;

            if ($investorSale->status !== 'active' && $part < Money::paisa($installment->amount)) {
                $installment->status = 'cancelled';
                $installment->save();
            } else {
                $this->payments->refreshInstallment($installment, $lastPaid);
            }
        }
    }

    /** A payment must be for this investor and this active investor sale, principal or profit, and no more than is left of it. */
    private function checkPayment(InvestorSale $investorSale, array $data, ?int $exceptId): void
    {
        if ($investorSale->status !== 'active' || $investorSale->investor_id !== (int) $data['investor_id']) {
            throw new BusinessRuleException('This investor sale is not active for this investor.');
        }

        // the shop pays the investor principal and profit; the investor gives the shop the rest of their amount (an investment)
        $owed = ['principal_return' => Money::paisa($investorSale->principal), 'profit_share' => Money::paisa($investorSale->profit_amount), 'investment' => $investorSale->investorAmount()];

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

    /** @return array{0: float, 1: int} the yearly profit rate (percent) and the profit in paisa, from either the rate or the amount given */
    private function profitOf(array $data, int $principal): array
    {
        if ($principal <= 0) {
            return [0.0, 0]; // no plan: nothing paid back, so no profit
        }

        // the rate is a flat yearly rate, as on a sale: 12% over 12 months is 12% of the amount, over 24 months 24%
        $years = max((int) ($data['installment_count'] ?? 0), 1) / (($data['frequency'] ?? 'monthly') === 'weekly' ? 52 : 12);

        if (isset($data['profit_amount']) && $data['profit_amount'] !== '') {
            $profit = Money::paisa($data['profit_amount']);

            return [round($profit / $principal * 100 / $years, 2), $profit];
        }

        $rate = round((float) $data['profit_rate'], 2);

        return [$rate, Money::percent($principal, $rate * $years)];
    }
}
