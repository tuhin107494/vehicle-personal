<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Advance;
use App\Models\Installment;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Payment;
use App\Models\PurchasePayment;
use App\Models\TransactionEdit;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edits the details of a recorded payment (date, method, reference, memo number, note) and keeps a history.
 * The amount can change too, as long as the figures it fed (the installment, the sale's or purchase's paid total) stay consistent.
 */
class TransactionService
{
    /** Payments taken when the sale was recorded: their amount is the sale's down payment, changed with the sale. */
    private const SALE_TIME_NOTES = ['Full payment at sale', 'Down payment', 'Advance'];

    public const TYPES = [
        'customer_payment' => Payment::class,
        'supplier_payment' => PurchasePayment::class,
        'supplier_advance' => Advance::class,
    ];

    public static function typeOf(Model $m): string
    {
        return array_search($m::class, self::TYPES, true);
    }

    /** Whether a payment's details can be edited here. */
    public static function editable(Model $m): bool
    {
        return match (true) {
            $m instanceof Payment => $m->amount > 0 && $m->method !== 'adjustment', // refunds and set-offs are not payments taken
            $m instanceof PurchasePayment => $m->method !== 'advance',
            default => true,
        };
    }

    /** @return array{type:string,id:int}|null what the statement needs to offer an Edit button */
    public static function tx(Model $m): ?array
    {
        return self::editable($m) ? ['type' => self::typeOf($m), 'id' => $m->id] : null;
    }

    public function find(string $type, int $id): Model
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type]::findOrFail($id);
    }

    /** @return array<string,?string> */
    public function values(Model $m): array
    {
        return [
            'amount' => $this->plain($m->amount),
            'amount_lock' => $this->amountLock($m),
            'note_locked' => $this->noteLocked($m) ? '1' : null,
            'paid_at' => $m->paid_at->toDateString(),
            'method' => $m->method,
            'reference' => $m->reference,
            'memo_no' => $m instanceof Payment ? $m->memo_no : null,
            'note' => $m->note,
        ];
    }

    /** An amount as plain text: 15000 when there are no decimals, 15000.5 or 15000.75 when there are. */
    private function plain(float|int|string $amount): string
    {
        $text = number_format((float) $amount, 2, '.', '');

        return rtrim(rtrim($text, '0'), '.');
    }

    /** A payment taken at the sale is recognised by its note ("Down payment"...), so the note stays as it is. */
    private function noteLocked(Model $m): bool
    {
        return $m instanceof Payment && $m->installment_id === null && in_array($m->note, self::SALE_TIME_NOTES, true);
    }

    /** Why this payment's amount cannot be changed, or null when it can. */
    public function amountLock(Model $m): ?string
    {
        if ($m instanceof Payment) {
            $sale = $m->sale()->with('plan')->first();

            return match (true) {
                $sale->status !== 'active' => 'The sale was cancelled, so its payments are fixed.',
                $this->noteLocked($m) => 'This was taken at the sale: change it with Edit sale.',
                (bool) $sale->plan?->investor_id => 'This sale is financed by an investor, whose share was already credited from this payment.',
                default => null,
            };
        }

        return null;
    }

    /** Applies a new amount (in paisa) to the payment and to every figure it fed. */
    private function changeAmount(Model $m, int $new): void
    {
        $old = Money::paisa($m->amount);

        if ($new <= 0) {
            throw new BusinessRuleException('The amount must be greater than zero.');
        }

        if ($lock = $this->amountLock($m)) {
            throw new BusinessRuleException($lock);
        }

        if ($m instanceof Payment) {
            $sale = Sale::lockForUpdate()->with(['plan.installments', 'payments'])->findOrFail($m->sale_id);
            $paidTotal = Money::paisa($sale->payments->sum('amount')) - $old + $new;

            if ($paidTotal > Money::paisa($sale->grandTotal())) {
                throw new BusinessRuleException('That would be more than the sale total of '.Money::format(Money::paisa($sale->grandTotal())).'.');
            }

            if ($m->installment_id) {
                $installment = Installment::lockForUpdate()->findOrFail($m->installment_id);
                $paid = Money::paisa($installment->paid_amount) - $old + $new;

                if ($paid > Money::paisa($installment->amount)) {
                    throw new BusinessRuleException('That is more than installment #'.$installment->seq_no.' ('.Money::format(Money::paisa($installment->amount)).').');
                }

                $installment->paid_amount = Money::taka($paid);
                app(PaymentService::class)->refreshInstallment($installment, $m->paid_at);
            }

            $m->update(['amount' => Money::taka($new)]);
            app(PaymentService::class)->recalculate($sale);

            return;
        }

        if ($m instanceof PurchasePayment) {
            $purchase = Purchase::lockForUpdate()->findOrFail($m->purchase_id);
            $paid = Money::paisa($purchase->paid_amount) - $old + $new;

            if ($paid + Money::paisa($purchase->due_adjustment) > Money::paisa($purchase->price)) {
                throw new BusinessRuleException('That is more than the purchase price of '.Money::format(Money::paisa($purchase->price)).'.');
            }

            $m->update(['amount' => Money::taka($new)]);
            $purchase->update([
                'paid_amount' => Money::taka($paid),
                'payment_status' => $paid + Money::paisa($purchase->due_adjustment) >= Money::paisa($purchase->price) ? 'paid' : 'partial',
            ]);

            return;
        }

        if ($new < Money::paisa($m->used)) {
            throw new BusinessRuleException('The advance cannot be less than the '.Money::format(Money::paisa($m->used)).' already used from it.');
        }

        $m->update(['amount' => Money::taka($new)]);
    }

    public function update(Model $m, array $data, User $user): Model
    {
        return DB::transaction(function () use ($m, $data, $user) {
            $m = $m::lockForUpdate()->findOrFail($m->id);

            if (! self::editable($m)) {
                throw new BusinessRuleException('This entry cannot be edited here.');
            }

            $date = Carbon::parse($data['paid_at']);
            $earliest = match (true) {
                $m instanceof Payment => $m->note === 'Advance' ? null : $m->sale->sale_date, // an advance was paid before the sale
                $m instanceof PurchasePayment => $m->purchase->purchase_date,
                default => null,
            };

            if ($earliest && $date->lt($earliest)) {
                throw new BusinessRuleException('The date cannot be before the '.($m instanceof Payment ? 'sale' : 'purchase').' date ('.$earliest->format('d M Y').').');
            }

            $before = $this->values($m);

            // the amount first, while the payment still shows what it was
            if (isset($data['amount']) && $data['amount'] !== '' && Money::paisa($data['amount']) !== Money::paisa($before['amount'])) {
                $this->changeAmount($m, Money::paisa($data['amount']));
            }

            $fields = [
                'paid_at' => $m instanceof Payment ? $date->setTimeFrom($m->paid_at) : $date,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'note' => $this->noteLocked($m) ? $m->note : ($data['note'] ?? null),
            ];

            if ($m instanceof Payment) {
                $fields['memo_no'] = $data['memo_no'] ?? null;
            }

            $m->update($fields);

            $changes = [];

            foreach (['amount' => 'Amount', 'paid_at' => 'Date', 'method' => 'Method', 'reference' => 'Reference', 'memo_no' => 'Memo no.', 'note' => 'Note'] as $key => $label) {
                $now = $this->values($m->fresh())[$key];

                if (($before[$key] ?? null) !== $now && ! (($before[$key] ?? null) === null && $now === '')) {
                    $changes[] = ['field' => $label, 'from' => $before[$key], 'to' => $now];
                }
            }

            if ($changes) {
                TransactionEdit::create(['type' => self::typeOf($m), 'subject_id' => $m->id, 'user_id' => $user->id, 'changes' => $changes]);
            }

            return $m;
        });
    }

    /** @return array<int,array{id:int,by:?string,at:string,changes:array}> newest first */
    public function history(string $type, int $id): array
    {
        return TransactionEdit::with('user:id,name')->where('type', $type)->where('subject_id', $id)->latest('id')->get()
            ->map(fn ($e) => ['id' => $e->id, 'by' => $e->user?->name, 'at' => $e->created_at->toIso8601String(), 'changes' => $e->changes])->all();
    }
}
