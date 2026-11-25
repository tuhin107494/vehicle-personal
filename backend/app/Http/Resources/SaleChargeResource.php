<?php

namespace App\Http\Resources;

use App\Models\SaleCharge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SaleCharge */
class SaleChargeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose,
            'amount' => $this->amount,
            'charged_on' => $this->charged_on->toDateString(),
            'note' => $this->note,
            // what has been paid of it, when its payments are loaded (SaleResource hands it the sale's own)
            'paid' => $this->relationLoaded('payments') ? (float) $this->payments->sum('amount') : null,
        ];
    }
}
