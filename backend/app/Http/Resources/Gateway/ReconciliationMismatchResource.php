<?php

namespace App\Http\Resources\Gateway;

use App\Models\Gateway\ReconciliationMismatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReconciliationMismatch */
class ReconciliationMismatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'gateway' => $this->gateway_code,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'kind' => $this->kind,
            'expected_paisa' => $this->expected_paisa,
            'actual_paisa' => $this->actual_paisa,
            'message' => $this->message,
            'details' => $this->details,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolver', fn () => $this->resolver?->only(['id', 'name'])),
            'note' => $this->note,
            'shop' => $this->whenLoaded('shop', fn () => $this->shop?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
