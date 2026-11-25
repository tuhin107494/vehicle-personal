<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user, as the app keeps it: who they are, their shop (with its subscription) and what they may do.
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'photo_url' => $this->photo_url,
            'role_label' => PermissionCatalog::ROLES[$this->role] ?? $this->role,
            'shop' => ShopResource::make($this->resource->loadMissing('shop.subscription.plan')->shop),
            'permissions' => $this->resource->permissionKeys(),
        ];
    }
}
