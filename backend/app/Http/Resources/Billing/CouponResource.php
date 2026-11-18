<?php

namespace App\Http\Resources\Billing;

use App\Models\Billing\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'max_uses' => $this->max_uses,
            'used_count' => $this->used_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'redeemable' => $this->isRedeemable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
