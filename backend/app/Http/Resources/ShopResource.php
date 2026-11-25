<?php

namespace App\Http\Resources;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shop */
class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'owner' => UserResource::make($this->whenLoaded('owner')),
            'users_count' => $this->whenCounted('users'),
            'subscription' => SubscriptionResource::make($this->whenLoaded('subscription')),
        ];
    }
}
