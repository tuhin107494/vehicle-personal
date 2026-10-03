<?php

namespace App\Models;

use App\Models\Scopes\CategoryScope;
use Illuminate\Database\Eloquent\Model;

class VehicleCategory extends Model
{
    protected $fillable = [
        'shop_id',
        'key',
        'name',
        'default_brand',
        'default_model',
        'default_color',
        'default_cc',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new CategoryScope);
    }
}
