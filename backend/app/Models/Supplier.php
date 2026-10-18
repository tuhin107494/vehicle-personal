<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Someone we buy vehicles from and pay. */
class Supplier extends Model
{
    use BelongsToShop, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'alt_phone',
        'nid',
        'address',
        'notes',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_no',
        'bank_info',
    ];

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** @return HasMany<Advance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }
}
