<?php

namespace App\Models\Billing;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A shop's plan over time. Status flow: trialing → active → past_due (grace) → expired, or cancelled at any point.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $grace_ends_at
 * @property Carbon|null $cancelled_at
 */
class Subscription extends Model
{
    use BelongsToShop;

    public const USABLE = ['trialing', 'active', 'past_due'];

    protected $fillable = [
        'shop_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'grace_ends_at',
        'cancelled_at',
        'auto_renew',
        'pending_plan_id',
        'last_reminder_days',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'trial_ends_at' => 'datetime', 'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime', 'auto_renew' => 'boolean', 'last_reminder_days' => 'integer',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** The shop may still use the app (a grace period counts). */
    public function isUsable(): bool
    {
        return in_array($this->status, self::USABLE, true);
    }
}
