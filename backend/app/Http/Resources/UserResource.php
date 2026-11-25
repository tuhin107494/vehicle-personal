<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // only the columns loaded: a relation loaded as `creator:id,name` (who made a sale) stays {id, name}
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->whenHas('email'),
            'role' => $this->whenHas('role'),
            'is_active' => $this->whenHas('is_active'),
            'shop_id' => $this->whenHas('shop_id'),
            'created_at' => $this->whenHas('created_at'),
            // a user just created has no photo yet (its column is not on the model until it is read again)
            'photo_url' => $this->whenHas('photo', fn () => $this->photo_url, fn () => $this->resource->wasRecentlyCreated ? null : new MissingValue),
            'role_label' => $this->whenHas('role', fn () => PermissionCatalog::ROLES[$this->role] ?? $this->role),
        ];
    }
}
