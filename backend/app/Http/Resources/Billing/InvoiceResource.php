<?php

namespace App\Http\Resources\Billing;

use App\Models\Billing\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'subscription_id' => $this->subscription_id,
            'invoice_no' => $this->invoice_no,
            'status' => $this->status,
            'payable' => $this->isPayable(),
            'amount_paisa' => $this->amount_paisa,
            'discount_paisa' => $this->discount_paisa,
            'total_paisa' => $this->total_paisa,
            'currency' => $this->currency,
            'period_starts_at' => $this->period_starts_at->toIso8601String(),
            'period_ends_at' => $this->period_ends_at->toIso8601String(),
            'due_date' => $this->due_date->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan?->only(['id', 'name', 'billing_interval'])),
            'coupon' => $this->whenLoaded('coupon', fn () => $this->coupon?->only(['id', 'code', 'type', 'value'])),
            'shop' => $this->whenLoaded('shop', fn () => $this->shop?->only(['id', 'name', 'phone', 'address'])),
        ];
    }
}
