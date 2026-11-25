<?php

namespace App\Http\Resources;

use App\Models\WalkIn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WalkIn */
class WalkInResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'name', 'phone', 'address', 'note', 'created_at'])
            + ['saved_by' => $this->whenLoaded('creator', fn () => $this->creator?->name)]
            // the customer with this mobile number, when they already are one (set by the list)
            + ['customer_id' => $this->resource->getAttribute('customer_id')];
    }
}
