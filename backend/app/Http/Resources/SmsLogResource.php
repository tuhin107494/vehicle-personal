<?php

namespace App\Http\Resources;

use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SmsLog */
class SmsLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer?->only(['id', 'name'])),
            'sale_id' => $this->sale_id,
            'due_type' => $this->due_type,
            'stage' => $this->stage,
            'phone' => $this->maskedPhone(), // staff see the number masked
            'message' => $this->message,
            'parts' => $this->parts,
            'cost' => $this->cost,
            'provider' => $this->provider,
            'status' => $this->status,
            'response' => $this->response,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
