<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\PaymentAttempt;
use App\Support\Photos;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentAttempt */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'shop_id' => $this->shop_id,
            'gateway' => $this->gateway_code,
            'amount_paisa' => $this->amount_paisa,
            'currency' => $this->currency,
            'status' => $this->status,
            'failure_reason' => $this->failure_reason,
            'reference' => $this->gateway_payment_id,
            'proof_url' => Photos::url($this->proof_path),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? ['invoice_no' => $this->invoice->invoice_no, 'total_paisa' => $this->invoice->total_paisa, 'status' => $this->invoice->status] : null),
            'shop' => $this->whenLoaded('invoice', fn () => $this->invoice?->relationLoaded('shop') ? ['id' => $this->invoice->shop->id, 'name' => $this->invoice->shop->name] : null),
            'initiation' => $this->when(isset($this->additional['initiation']), fn () => $this->additional['initiation']),
        ];
    }
}
