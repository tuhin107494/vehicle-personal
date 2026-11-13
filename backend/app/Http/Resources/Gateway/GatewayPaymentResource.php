<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\GatewayRefund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A confirmed payment as the super admin's payments page sees it.
 *
 * @mixin GatewayPayment
 */
class GatewayPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_id' => $this->attempt_id,
            'invoice_id' => $this->invoice_id,
            'shop_id' => $this->shop_id,
            'gateway' => $this->gateway_code,
            'gateway_trx_id' => $this->gateway_trx_id,
            'amount_paisa' => $this->amount_paisa,
            'fee_paisa' => $this->fee_paisa,
            'refunded_paisa' => $this->refunded_paisa,
            'refundable_paisa' => $this->refundablePaisa(),
            'status' => $this->status,
            'paid_at' => $this->paid_at->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice?->only(['id', 'invoice_no', 'status', 'total_paisa'])),
            'shop' => $this->whenLoaded('shop', fn () => $this->shop?->only(['id', 'name'])),
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds->map(fn (GatewayRefund $r) => GatewayRefundResource::make($r)->resolve($request))),
        ];
    }
}
