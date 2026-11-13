<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A platform plan a shop subscribes to. Prices are whole paisa. */
class Plan extends Model
{
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

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'plan_features')->withPivot('value');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** What the discount takes off the regular price, in paisa; 0 when the plan has no discount. */
    public function discountPaisa(): int
    {
        return max(($this->original_price_paisa ?? 0) - $this->price_paisa, 0);
    }

    /** The discount as a whole percent of the regular price, for a "Save 17%" label; 0 when there is none. */
    public function discountPercent(): int
    {
        $original = $this->original_price_paisa ?? 0;

        return $original > 0 ? (int) round($this->discountPaisa() * 100 / $original) : 0;
    }

    public function isFree(): bool
    {
        return $this->price_paisa === 0;
    }

    /** Every interval a plan may run for, with its length in months. The plan form and the period maths both read this. */
    public const INTERVALS = ['monthly' => 1, 'quarterly' => 3, 'half_yearly' => 6, 'yearly' => 12];

    /** How long one paid period lasts. */
    public function periodMonths(): int
    {
        return self::INTERVALS[$this->billing_interval];
    }
}
