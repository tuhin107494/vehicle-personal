<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed> $data the form's saved state: its values (and, for a purchase, the source)
 * @property Carbon|null $expires_on
 */
class Draft extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'type',
        'vehicle_id',
        'label',
        'data',
        'expires_on',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'expires_on' => 'date:Y-m-d'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
