<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'party' => PartyResource::make($this->whenLoaded('party')),
        ];
    }
}
