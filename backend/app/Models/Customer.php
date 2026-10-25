<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPhoto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Someone we sell vehicles to (and who may be bought a second-hand vehicle back from). */
class Customer extends Model
{
    use BelongsToShop, HasPhoto, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'name',
        'father_name',
        'phone',
        'alt_phone',
        'nid',
        'address',
        'notes',
    ];

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * Vehicles bought back from this customer.
     *
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }
}
