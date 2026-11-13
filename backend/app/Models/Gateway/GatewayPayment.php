<?php

namespace App\Models\Gateway;

use App\Models\Billing\Invoice;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money that really arrived for an invoice, written only after the gateway confirmed it. One per attempt, one per
 * gateway transaction id, so a repeated webhook cannot record it twice.
 *
 * @property Carbon $paid_at
 */
class GatewayPayment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'attempt_id',
        'invoice_id',
        'shop_id',
        'gateway_code',
        'gateway_trx_id',
        'amount_paisa',
        'fee_paisa',
        'refunded_paisa',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return ['amount_paisa' => 'integer', 'fee_paisa' => 'integer', 'refunded_paisa' => 'integer', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<PaymentAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'attempt_id');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<GatewayRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(GatewayRefund::class);
    }

    /** What can still be refunded, in paisa. */
    public function refundablePaisa(): int
    {
        return max($this->amount_paisa - $this->refunded_paisa, 0);
    }
}
