<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'name', 'email', 'role', 'is_active', 'shop_id', 'created_at', 'photo_url'])
            + ['role_label' => PermissionCatalog::ROLES[$this->role] ?? $this->role];
    }
}
