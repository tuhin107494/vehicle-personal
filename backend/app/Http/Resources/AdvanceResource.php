<?php

namespace App\Http\Resources;

use App\Models\Advance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Advance */
class AdvanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'amount' => (float) $this->amount,
            'used' => (float) $this->used,
            'remaining' => $this->remaining,
            'method' => $this->method,
            'paid_at' => $this->paid_at->toDateString(),
            'reference' => $this->reference,
            'note' => $this->note,
            'created_at' => $this->created_at, // written like every other response's dates (ISO 8601, UTC)
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->only(['id', 'name', 'phone'])),
        ];
    }
}
