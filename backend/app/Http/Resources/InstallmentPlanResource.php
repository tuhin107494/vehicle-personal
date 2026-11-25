<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InstallmentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'installments' => InstallmentResource::collection($this->whenLoaded('installments')),
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
            'sale' => SaleResource::make($this->whenLoaded('sale')),
        ];
    }
}
