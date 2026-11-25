<?php

namespace App\Http\Resources;

use App\Models\PurchasePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchasePayment */
class PurchasePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_id' => $this->purchase_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'paid_at' => $this->paid_at->toDateString(),
            'reference' => $this->reference,
            'note' => $this->note,
            'created_at' => $this->created_at, // written like every other response's dates (ISO 8601, UTC)
        ];
    }
}
