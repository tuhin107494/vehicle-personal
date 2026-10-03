<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'key',
        'value',
    ];
}
