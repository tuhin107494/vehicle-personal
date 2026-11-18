<?php

namespace App\Http\Resources\Billing;

use App\Models\Billing\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Subscription */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'status' => $this->status,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'auto_renew' => $this->auto_renew,
            'days_left' => max((int) ceil(now()->diffInDays($this->ends_at, false)), 0),
            'plan' => PlanResource::make($this->whenLoaded('plan')),
            'pending_plan' => PlanResource::make($this->whenLoaded('pendingPlan')),
            'shop' => $this->whenLoaded('shop', fn () => $this->shop?->only(['id', 'name', 'phone', 'is_active'])),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
