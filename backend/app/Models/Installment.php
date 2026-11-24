<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 */
class Installment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'plan_id',
        'investor_sale_id',
        'seq_no',
        'due_date',
        'amount',
        'paid_amount',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date:Y-m-d',
            'amount' => 'float',
            'paid_amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InstallmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    /**
     * The shop pays the investor back on an investor sale.
     *
     * @return BelongsTo<InvestorSale, $this>
     */
    public function investorSale(): BelongsTo
    {
        return $this->belongsTo(InvestorSale::class);
    }

    /**
     * Only the customers' installments (what customers pay the shop), not the investors'.
     *
     * @param  Builder<Installment>  $query
     */
    public function scopeCustomer(Builder $query): void
    {
        $query->whereNotNull('plan_id');
    }

    /** How the installment is named to people: number 0 is a sale's promised amount (paid by its date, outside the installments). */
    public static function labelOf(int $seqNo): string
    {
        return $seqNo === 0 ? 'Promise' : "Installment #$seqNo";
    }

    public function remaining(): float
    {
        return Money::add($this->amount, -$this->paid_amount);
    }
}
