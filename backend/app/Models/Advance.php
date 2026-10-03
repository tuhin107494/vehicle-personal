<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advance extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'party_id',
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
        return round($this->amount - $this->used, 2);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
