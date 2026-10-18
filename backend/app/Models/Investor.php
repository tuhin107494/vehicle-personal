<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Investor extends Model
{
    use BelongsToShop, SoftDeletes;

    protected $fillable = [
        'shop_id',
        'name',
        'phone',
        'alt_phone',
        'nid',
        'address',
        'bank_info',
        'opening_balance',
        'notes',
        'is_active',
        'bank_name',
        'bank_branch',
        'bank_account_name',
        'bank_account_no',
        'formula_invested',
        'formula_profit',
        'formula_months',
        'formula_rate_month',
        'formula_rate_year',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'float',
            'is_active' => 'boolean',
            'formula_invested' => 'float',
            'formula_profit' => 'float',
            'formula_rate_month' => 'float',
            'formula_rate_year' => 'float',
        ];
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<InstallmentPlan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    /** @return HasMany<InvestorSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(InvestorSettlement::class);
    }
}
