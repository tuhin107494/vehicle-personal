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
}
