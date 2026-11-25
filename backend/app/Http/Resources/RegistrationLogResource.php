<?php

namespace App\Http\Resources;

use App\Models\RegistrationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RegistrationLog */
class RegistrationLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(), // its own columns: relations only as mapped below
            'user' => UserResource::make($this->whenLoaded('user')),
        ];
    }
}
