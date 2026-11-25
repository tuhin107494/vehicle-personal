<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Purchase */
class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'supplier' => SupplierResource::make($this->whenLoaded('supplier')),
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'creator' => UserResource::make($this->whenLoaded('creator')),
            'edits' => PurchaseEditResource::collection($this->whenLoaded('edits')),
            // paid amount can only change through payments once any are recorded
            'has_payments' => $this->whenCounted('payments', fn () => $this->payments_count > 0),
            // whoever sold it to us: the supplier, or the customer for a buy-back
            'seller' => $this->when($this->relationLoaded('supplier') || $this->relationLoaded('customer'), fn () => ($seller = $this->resource->seller()) instanceof Supplier
                ? SupplierResource::make($seller)
                : CustomerResource::make($seller)),
        ];
    }
}
