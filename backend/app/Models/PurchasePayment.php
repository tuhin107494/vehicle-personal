<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\Cancellable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $paid_at
 * @property string|null $cancel_reason
 */
class PurchasePayment extends Model
{
    use BelongsToShop, Cancellable;

    protected $fillable = [
        'shop_id',
        'purchase_id',
        'amount',
        'method',
        'paid_at',
        'reference',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_at' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
