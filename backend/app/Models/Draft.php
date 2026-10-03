<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Draft extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'type',
        'label',
        'data',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
