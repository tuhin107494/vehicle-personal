<?php

namespace App\Http\Resources;

use App\Models\DuePromise;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DuePromise */
class DuePromiseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            // an open promise whose date has passed is a broken one
            'status' => $this->status === 'open' && $this->promised_date->lt(today()) ? 'broken' : $this->status,
            'sale' => SaleResource::make($this->whenLoaded('sale')),
        ];
    }
}
