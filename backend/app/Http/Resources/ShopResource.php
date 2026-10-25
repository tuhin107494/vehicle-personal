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
            ...parent::toArray($request),
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name', 'email', 'is_active'])),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
