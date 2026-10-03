<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Advance;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** What the shop still owes on purchases ("due paid") and money handed over before a purchase ("advance paid"). */
class PayableService
{
    public function balance(Purchase $purchase): float
    {
        return round($purchase->price - $purchase->paid_amount - $purchase->due_adjustment, 2);
    }

    public function advanceBalance(int $supplierId): float
    {
        return round((float) Advance::where('supplier_id', $supplierId)->selectRaw('COALESCE(SUM(amount - used), 0) as r')->value('r'), 2);
    }

    public function pay(Purchase $purchase, array $data, User $user): PurchasePayment
    {
        return DB::transaction(function () use ($purchase, $data, $user) {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            $amount = round((float) $data['amount'], 2);
            $balance = $this->balance($purchase);

            if ($balance <= 0) {
                throw new BusinessRuleException('This purchase is already paid in full.');
            }

            if ($amount > $balance) {
                throw new BusinessRuleException('Amount is more than the '.number_format($balance, 2).' still owed on this purchase.');
            }

            if ($data['method'] === 'advance') {
                $this->useAdvance($purchase->supplier_id, $amount);
            }

            $payment = PurchasePayment::create([
                'purchase_id' => $purchase->id,
                'amount' => $amount,
                'method' => $data['method'],
                'paid_at' => $data['paid_at'] ?? today()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $paid = round($purchase->paid_amount + $amount, 2);
            $purchase->update([
                'paid_amount' => $paid,
                'payment_status' => $paid + $purchase->due_adjustment >= $purchase->price ? 'paid' : 'partial',
            ]);

            return $payment;
        });
    }

    public function addAdvance(array $data, User $user): Advance
    {
        return Advance::create([
            'supplier_id' => $data['supplier_id'],
            'amount' => $data['amount'],
            'method' => $data['method'],
            'paid_at' => $data['paid_at'] ?? today()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /** Takes the amount out of the supplier's advances, oldest first. */
    private function useAdvance(int $supplierId, float $amount): void
    {
        $advances = Advance::where('supplier_id', $supplierId)->whereColumn('used', '<', 'amount')->orderBy('paid_at')->orderBy('id')->lockForUpdate()->get();

        if (round($advances->sum(fn ($a) => $a->amount - $a->used), 2) < $amount) {
            throw new BusinessRuleException('This supplier has only '.number_format($advances->sum(fn ($a) => $a->amount - $a->used), 2).' of advance left.');
        }

        foreach ($advances as $advance) {
            if ($amount <= 0) {
                break;
            }

            $take = min($amount, $advance->amount - $advance->used);
            $advance->update(['used' => round($advance->used + $take, 2)]);
            $amount = round($amount - $take, 2);
        }
    }
}
