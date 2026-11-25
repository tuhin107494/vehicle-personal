<?php

namespace App\Http\Resources;

use App\Models\VehicleCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin VehicleCategory */
class VehicleCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id, // null: a platform type every shop uses
            'key' => $this->key,
            'name' => $this->name,
            'default_brand' => $this->default_brand,
            'default_model' => $this->default_model,
            'default_color' => $this->default_color,
            'default_cc' => $this->default_cc,
        ];
    }
}
