<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A discount code. Percent coupons hold whole percent; fixed coupons hold paisa.
 *
 * @property Carbon|null $expires_at
 */
class Coupon extends Model
{
    protected $fillable = ['code', 'type', 'value', 'max_uses', 'used_count', 'expires_at', 'is_active'];

    // the same defaults as the table, so a coupon just created answers isRedeemable() without a re-read
    protected $attributes = ['used_count' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['value' => 'integer', 'max_uses' => 'integer', 'used_count' => 'integer', 'expires_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /** Can still be applied: switched on, not expired, uses left. */
    public function isRedeemable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->used_count < $this->max_uses);
    }
}
