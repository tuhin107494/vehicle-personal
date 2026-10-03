<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPlan extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'sale_id',
        'investor_id',
        'principal',
        'interest_rate',
        'interest_amount',
        'total_payable',
        'number_of_installments',
        'frequency',
        'start_date',
        'investor_share_percent',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'float',
            'interest_rate' => 'float',
            'interest_amount' => 'float',
            'total_payable' => 'float',
            'start_date' => 'date:Y-m-d',
            'investor_share_percent' => 'float',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class, 'plan_id');
    }
}
