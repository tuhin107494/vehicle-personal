<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The investor's side of a customer sale: the amount they funded, their profit, and the fixed schedule the shop pays them back on.
 *
 * @property Carbon $date
 * @property Carbon $start_date
 * @property Carbon|null $cancelled_at
 */
class InvestorSale extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'investor_id',
        'customer_id',
        'date',
        'sale_price',
        'down_payment',
        'investor_down_payment',
        'method',
        'memo_no',
        'guarantor_name',
        'guarantor_phone',
        'principal',
        'profit_rate',
        'profit_amount',
        'total_payable',
        'number_of_installments',
        'frequency',
        'start_date',
        'status',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sale_id' => 'integer',
            'investor_id' => 'integer',
            'customer_id' => 'integer',
            'date' => 'date:Y-m-d',
            'sale_price' => 'float',
            'down_payment' => 'float',
            'investor_down_payment' => 'float',
            'principal' => 'float',
            'profit_rate' => 'float',
            'profit_amount' => 'float',
            'total_payable' => 'float',
            'start_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Investor, $this> */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /**
     * The additional customer on this investor sale, besides the sale's buyer.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<InvestorSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(InvestorSettlement::class);
    }

    /**
     * What the shop pays the investor and when: the total split evenly over the installments (the last one takes the rounding).
     *
     * @return list<array{seq_no:int,due_date:Carbon,amount:int}> amounts in paisa
     */
    /**
     * The stored installments the shop pays the investor back on (the schedule, with what has been paid on each).
     *
     * @return HasMany<Installment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('seq_no');
    }

    public function schedule(): array
    {
        $rows = [];

        foreach (Money::split(Money::paisa($this->total_payable), (int) $this->number_of_installments) as $i => $amount) {
            $due = $this->frequency === 'weekly' ? $this->start_date->copy()->addWeeks($i) : $this->start_date->copy()->addMonthsNoOverflow($i);
            $rows[] = ['seq_no' => $i + 1, 'due_date' => $due, 'amount' => $amount];
        }

        return $rows;
    }
}
