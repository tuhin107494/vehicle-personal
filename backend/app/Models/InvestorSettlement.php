<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $date
 */
class InvestorSettlement extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'investor_id',
        'plan_id',
        'investor_sale_id',
        'type',
        'amount',
        'date',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'date' => 'date:Y-m-d',
        ];
    }

    /**
     * Made by the system on a plan an investor finances: the investment at the sale, the principal and profit shares of
     * each collection, the principal returned on a cancel. Each changes only with what made it, never by hand.
     */
    public function isAutomatic(): bool
    {
        return $this->plan_id !== null && str_starts_with((string) $this->note, 'Auto:');
    }

    /** @return BelongsTo<Investor, $this> */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    /** @return BelongsTo<InstallmentPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    /** @return BelongsTo<InvestorSale, $this> */
    public function investorSale(): BelongsTo
    {
        return $this->belongsTo(InvestorSale::class);
    }
}
