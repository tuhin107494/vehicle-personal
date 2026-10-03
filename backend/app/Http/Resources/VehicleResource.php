<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'current_purchase' => PurchaseResource::make($this->whenLoaded('currentPurchase')),
            'current_sale' => SaleResource::make($this->whenLoaded('currentSale')),
        ];
    }
}
