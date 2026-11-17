<?php

namespace App\Models\Billing;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A shop's plan over time. A shop's current subscription is its latest row.
 * Whether it still runs is read from the clock (ends_at), so no scheduler is needed to lock a shop on time.
 *
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $trial_ends_at
 */
class Subscription extends Model
{
    use BelongsToShop;

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

    /** The shop may still use the app: not cancelled or expired, and its end time is still ahead. */
    public function isUsable(): bool
    {
        return in_array($this->status, ['trialing', 'active'], true) && $this->ends_at->isFuture();
    }

    /** What the screens show: trialing, active or expired (an ended trial or period reads as expired). */
    public function state(): string
    {
        return $this->isUsable() ? $this->status : 'expired';
    }

    /** @return array{plan: string, status: string, ends_at: string} */
    public function summary(): array
    {
        return ['plan' => $this->plan->name, 'status' => $this->state(), 'ends_at' => $this->ends_at->toIso8601String()];
    }
}
