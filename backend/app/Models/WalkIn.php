<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone who visited the shop but has not bought yet. */
class WalkIn extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'address',
        'note',
        'created_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
