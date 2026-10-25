<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $promised_date
 */
class DuePromise extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'promised_date',
        'promised_amount',
        'status',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'promised_date' => 'date:Y-m-d',
            'promised_amount' => 'float',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
