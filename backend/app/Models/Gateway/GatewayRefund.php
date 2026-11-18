<?php

namespace App\Models\Gateway;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A refund the super admin asked a gateway for. Carries shop_id for filtering but no shop scope: only the platform handles refunds. */
class GatewayRefund extends Model
{
    protected $fillable = [
        'gateway_payment_id',
        'shop_id',
        'amount_paisa',
        'reason',
        'status',
        'gateway_refund_id',
        'failure_reason',
        'requested_by',
    ];

    protected function casts(): array
    {
        return ['amount_paisa' => 'integer'];
    }

    /** @return BelongsTo<GatewayPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class, 'gateway_payment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
