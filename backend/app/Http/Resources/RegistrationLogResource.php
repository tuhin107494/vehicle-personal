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
            ...parent::toArray($request),
            'user' => $this->whenLoaded('user', fn () => $this->user->only(['id', 'name'])),
        ];
    }
}
