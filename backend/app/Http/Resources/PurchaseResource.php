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
            'edits' => $this->whenLoaded('edits', fn () => $this->edits->map(fn ($e) => ['id' => $e->id, 'by' => $e->user?->name, 'at' => $e->created_at->toIso8601String(), 'changes' => $e->changes])),
            // paid amount can only change through payments once any are recorded
            'has_payments' => $this->whenCounted('payments', fn () => $this->payments_count > 0),
            // whoever sold it to us: the supplier, or the customer for a buy-back
            'seller' => $this->when($this->relationLoaded('supplier') || $this->relationLoaded('customer'), fn () => $this->resource->seller()?->only(['id', 'name', 'phone', 'alt_phone'])),
        ];
    }
}
