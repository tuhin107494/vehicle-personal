<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Advance;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** What the shop still owes on purchases ("due paid") and money handed over before a purchase ("advance paid"). */
class PayableService
{
    public function balance(Purchase $purchase): float
    {
        return Money::add($purchase->price, -$purchase->paid_amount, -$purchase->due_adjustment);
    }

    public function advanceBalance(int $supplierId): float
    {
        return (float) Advance::where('supplier_id', $supplierId)->selectRaw('COALESCE(SUM(amount - used), 0) as r')->value('r');
    }

    public function pay(Purchase $purchase, array $data, User $user): PurchasePayment
    {
        return DB::transaction(function () use ($purchase, $data, $user) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            $amount = Money::paisa($data['amount']);
            $balance = Money::paisa($this->balance($purchase));

            if ($balance <= 0) {
                throw new BusinessRuleException('This purchase is already paid in full.');
            }

            if ($amount > $balance) {
                throw new BusinessRuleException('Amount is more than the '.Money::format($balance).' still owed on this purchase.');
            }

            if ($data['method'] === 'advance') {
                if (! $purchase->supplier_id) {
                    throw new BusinessRuleException('Advances are held for suppliers, so they cannot pay a purchase from a customer.');
                }

                $this->useAdvance($purchase->supplier_id, $amount);
            }

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'amount' => Money::taka($amount),
                'method' => $data['method'],
                'paid_at' => $data['paid_at'] ?? today()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $paid = Money::paisa($purchase->paid_amount) + $amount;
            $purchase->update([
                'paid_amount' => Money::taka($paid),
                'payment_status' => $paid + Money::paisa($purchase->due_adjustment) >= Money::paisa($purchase->price) ? 'paid' : 'partial',
            ]);

            return $payment;
        });
    }

    public function addAdvance(array $data, User $user): Advance
    {
        return Advance::create([
            'supplier_id' => $data['supplier_id'],
            'amount' => Money::taka(Money::paisa($data['amount'])),
            'method' => $data['method'],
            'paid_at' => $data['paid_at'] ?? today()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /** Takes the amount (in paisa) out of the supplier's advances, oldest first. */
    private function useAdvance(int $supplierId, int $amount): void
    {
        $advances = Advance::where('supplier_id', $supplierId)->whereColumn('used', '<', 'amount')->orderBy('paid_at')->orderBy('id')->lockForUpdate()->get();
        $left = fn (Advance $a) => Money::paisa($a->remaining);
        $available = $advances->sum($left);

        if ($available < $amount) {
            throw new BusinessRuleException('This supplier has only '.Money::format($available).' of advance left.');
        }

        foreach ($advances as $advance) {
            if ($amount <= 0) {
                break;
            }

            $take = min($amount, $left($advance));
            $advance->update(['used' => Money::taka(Money::paisa($advance->used) + $take)]);
            $amount -= $take;
        }
    }
}
