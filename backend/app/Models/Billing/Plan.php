<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A platform plan a shop subscribes to. Prices are whole paisa. */
class Plan extends Model
{
    /** Every interval a plan may run for, with its length in months. */
    public const INTERVALS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    protected $fillable = [
        'name',
        'slug',
        'price_paisa',
        'original_price_paisa',
        'currency',
        'billing_interval',
        'trial_days',
        'is_active',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return ['price_paisa' => 'integer', 'original_price_paisa' => 'integer', 'trial_days' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
