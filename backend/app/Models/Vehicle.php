<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use BelongsToShop, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'chassis_no',
        'engine_no',
        'registration_no',
        'vehicle_type',
        'brand',
        'model',
        'year',
        'color',
        'cc',
        'fuel_type',
        'status',
        'current_purchase_id',
        'current_sale_id',
        'registration_date',
        'tax_token_expiry',
        'fitness_expiry',
        'insurance_expiry',
        'papers_received',
        'mileage',
        'condition_notes',
    ];

    protected function casts(): array
    {
        return [
            'registration_date' => 'date:Y-m-d',
            'tax_token_expiry' => 'date:Y-m-d',
            'fitness_expiry' => 'date:Y-m-d',
            'insurance_expiry' => 'date:Y-m-d',
            'papers_received' => 'boolean',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function currentPurchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'current_purchase_id');
    }

    public function currentSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'current_sale_id');
    }
}
