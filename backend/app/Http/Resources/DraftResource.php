<?php

namespace App\Http\Resources;

use App\Models\Draft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Draft */
class DraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'vehicle_id' => $this->vehicle_id, 'expires_on' => $this->expires_on?->toDateString(),
            'label' => $this->label, 'data' => $this->data,
            'saved_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // in the booking list: the vehicle it holds and the customer chosen so far (looked up by BookingController)
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
        ];
    }
}
