<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestorSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'investor' => InvestorResource::make($this->whenLoaded('investor')),
        ];
    }
}
