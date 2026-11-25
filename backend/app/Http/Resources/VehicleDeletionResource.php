<?php

namespace App\Http\Resources;

use App\Models\VehicleDeletion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleDeletion */
class VehicleDeletionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'chassis_no' => $this->chassis_no,
            'engine_no' => $this->engine_no,
            'registration_no' => $this->registration_no,
            'vehicle_name' => $this->vehicle_name,
            'summary' => $this->summary, // what was removed with it: sales, purchases, investor sales and totals
            'created_at' => $this->created_at, // written like every other response's dates (ISO 8601, UTC)
            'user' => $this->whenLoaded('user', fn () => $this->user?->only(['id', 'name'])),
        ];
    }
}
