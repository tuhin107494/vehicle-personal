<?php

namespace App\Models\Gateway;

use App\Models\Billing\Invoice;
use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One try at paying an invoice through a gateway. Statuses only move forward: a late failure never overwrites a success.
 *
 * @property Carbon|null $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 */
class PaymentAttempt extends Model
{
    use BelongsToShop;

    public const OPEN = ['initiated', 'pending'];

    public const FINAL = ['success', 'failed', 'cancelled', 'expired'];

    protected $fillable = [
        'shop_id',
        'invoice_id',
        'gateway_code',
        'amount_paisa',
        'currency',
        'idempotency_key',
        'gateway_payment_id',
        'status',
        'failure_reason',
        'expires_at',
        'verified_at',
        'proof_path',
        'submitted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['amount_paisa' => 'integer', 'expires_at' => 'datetime', 'verified_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasOne<GatewayPayment, $this> */
    public function payment(): HasOne
    {
        return $this->hasOne(GatewayPayment::class, 'attempt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }
}
