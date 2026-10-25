<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $paid_at
 */
class PurchasePayment extends Model
{
    use BelongsToShop;

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
}
