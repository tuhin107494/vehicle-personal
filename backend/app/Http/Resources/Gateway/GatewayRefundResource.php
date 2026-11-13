<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\GatewayRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GatewayRefund */
class GatewayRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gateway_payment_id' => $this->gateway_payment_id,
            'shop_id' => $this->shop_id,
            'amount_paisa' => $this->amount_paisa,
            'reason' => $this->reason,
            'status' => $this->status,
            'gateway_refund_id' => $this->gateway_refund_id,
            'failure_reason' => $this->failure_reason,
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->only(['id', 'name'])),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment?->only(['id', 'gateway_code', 'gateway_trx_id', 'amount_paisa'])),
            'shop' => $this->whenLoaded('shop', fn () => $this->shop?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
