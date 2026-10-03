<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    use BelongsToShop, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'name',
        'father_name',
        'phone',
        'alt_phone',
        'nid',
        'address',
        'type',
        'notes',
        'bank_info',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_no',
    ];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
