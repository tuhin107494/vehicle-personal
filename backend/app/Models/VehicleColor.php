<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/** A colour a shop added on top of the standard list shown on the vehicle form. */
class VehicleColor extends Model
{
    use BelongsToShop;

    public const STANDARD = ['Green', 'Red', 'Maroon', 'Black', 'Blue'];

    protected $fillable = [
        'shop_id',
        'name',
    ];
}
