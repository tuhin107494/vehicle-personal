<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'current_purchase' => PurchaseResource::make($this->whenLoaded('currentPurchase')),
            'current_sale' => SaleResource::make($this->whenLoaded('currentSale')),
        ];
    }
}
