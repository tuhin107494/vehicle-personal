<?php

namespace App\Models\Billing;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a shop owes the platform for one period. Paid through the payment module, which reports back by event.
 *
 * @property Carbon $period_starts_at
 * @property Carbon $period_ends_at
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 */
class Invoice extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'subscription_id',
        'plan_id',
        'coupon_id',
        'invoice_no',
        'amount_paisa',
        'discount_paisa',
        'total_paisa',
        'currency',
        'status',
        'period_starts_at',
        'period_ends_at',
        'due_date',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_paisa' => 'integer', 'discount_paisa' => 'integer', 'total_paisa' => 'integer',
            'period_starts_at' => 'datetime', 'period_ends_at' => 'datetime', 'due_date' => 'date', 'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isPayable(): bool
    {
        return $this->status === 'unpaid' && $this->total_paisa > 0;
    }
}
