<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Purchase */
class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            // whoever sold it to us: the supplier, or the customer for a buy-back
            'seller' => $this->when($this->relationLoaded('supplier') || $this->relationLoaded('customer'), fn () => $this->resource->seller()?->only(['id', 'name', 'phone', 'alt_phone'])),
        ];
    }
}
