<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $paid_at
 */
class Advance extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'supplier_id',
        'amount',
        'used',
        'method',
        'paid_at',
        'reference',
        'note',
        'created_by',
    ];

    protected $appends = ['remaining'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'used' => 'float', 'paid_at' => 'date:Y-m-d'];
    }

    public function getRemainingAttribute(): float
    {
        return Money::add($this->amount, -$this->used);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
