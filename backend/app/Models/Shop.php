<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shop extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'address',
        'is_active',
        'approved_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'approved_at' => 'datetime'];
    }

    /** Signed itself up and the super admin has not switched it on yet. */
    public function isPending(): bool
    {
        return $this->approved_at === null;
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasOne<User, $this> */
    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'shop_owner');
    }
}
