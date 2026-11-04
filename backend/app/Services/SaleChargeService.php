<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleCharge;
use App\Models\TransactionEdit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** Extra money a customer owes on a sale: it adds to the sale's due, and customers pay it through the normal collection. */
class SaleChargeService
{
    public function __construct(private PaymentService $payments) {}

    public function add(Sale $sale, array $data, User $user): SaleCharge
    {
        return DB::transaction(function () use ($sale, $data, $user) {
            $sale = $this->lockActive($sale->id);

            $charge = SaleCharge::create([
                'sale_id' => $sale->id,
                'purpose' => $data['purpose'],
                'amount' => Money::taka(Money::paisa($data['amount'])),
                'charged_on' => $data['charged_on'],
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->refresh($sale);

            return $charge;
        });
    }

    public function update(SaleCharge $charge, array $data, User $user): SaleCharge
    {
        return DB::transaction(function () use ($charge, $data, $user) {
            $charge = SaleCharge::lockForUpdate()->findOrFail($charge->id);
            $sale = $this->lockActive($charge->sale_id);
            $paid = $this->paid($charge);
            $new = Money::paisa($data['amount']);

            if ($new < $paid) {
                throw new BusinessRuleException('The charge cannot be less than the '.Money::format($paid).' already paid on it.');
            }

            $before = $this->values($charge);
            $charge->update([
                'purpose' => $data['purpose'],
                'amount' => Money::taka($new),
                'charged_on' => $data['charged_on'],
                'note' => $data['note'] ?? null,
            ]);
            $this->refresh($sale);

            $changes = [];

            foreach (['purpose' => 'Purpose', 'amount' => 'Amount', 'charged_on' => 'Date', 'note' => 'Note'] as $key => $label) {
                $now = $this->values($charge->fresh())[$key];

                if (($before[$key] ?? null) !== $now && ! (($before[$key] ?? null) === null && $now === '')) {
                    $changes[] = ['field' => $label, 'from' => $before[$key], 'to' => $now];
                }
            }

            $changes && TransactionEdit::create(['type' => 'sale_charge', 'subject_id' => $charge->id, 'user_id' => $user->id, 'changes' => $changes]);

            return $charge;
        });
    }

    public function delete(SaleCharge $charge, User $user): void
    {
        DB::transaction(function () use ($charge, $user) {
            $charge = SaleCharge::lockForUpdate()->findOrFail($charge->id);
            $sale = $this->lockActive($charge->sale_id);

            if ($this->paid($charge) > 0) {
                throw new BusinessRuleException('Something has already been paid on this charge, so it cannot be deleted.');
            }

            TransactionEdit::create(['type' => 'sale_charge', 'subject_id' => $charge->id, 'user_id' => $user->id, 'changes' => [['field' => 'Charge', 'from' => $charge->purpose.' '.$this->plain($charge->amount), 'to' => 'Deleted']]]);
            $charge->delete();
            $this->refresh($sale);
        });
    }

    /** @return array<string,?string> */
    public function values(SaleCharge $charge): array
    {
        return ['purpose' => $charge->purpose, 'amount' => $this->plain($charge->amount), 'charged_on' => $charge->charged_on->toDateString(), 'note' => $charge->note];
    }

    /** @return array<int,array{id:int,by:?string,at:string,changes:array}> */
    public function history(SaleCharge $charge): array
    {
        return TransactionEdit::with('user:id,name')->where('type', 'sale_charge')->where('subject_id', $charge->id)->latest('id')->get()
            ->map(fn ($e) => ['id' => $e->id, 'by' => $e->user?->name, 'at' => $e->created_at->toIso8601String(), 'changes' => $e->changes])->all();
    }

    private function lockActive(int $saleId): Sale
    {
        $sale = Sale::lockForUpdate()->findOrFail($saleId);

        if ($sale->status !== 'active') {
            throw new BusinessRuleException('Charges can only be added to an active sale.');
        }

        return $sale;
    }

    /** Paid on a charge so far, in paisa. */
    private function paid(SaleCharge $charge): int
    {
        return Money::paisa(Payment::where('sale_charge_id', $charge->id)->sum('amount'));
    }

    /** The sale's charges total changed: its due follows. */
    private function refresh(Sale $sale): void
    {
        $sale->update(['charges_total' => Money::taka(Money::paisa(SaleCharge::where('sale_id', $sale->id)->sum('amount')))]);
        $this->payments->recalculate($sale);
    }

    private function plain(float|int|string $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }
}
