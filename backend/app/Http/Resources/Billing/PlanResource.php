<?php

namespace App\Http\Resources\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A plan for the public list, the shop's billing page and the super admin. `features` is key => value ("1"/"0" for a
 * switch, a number or null for a limit) when loaded.
 *
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price_paisa' => $this->price_paisa, // what the shop pays: the price after any discount
            'original_price_paisa' => $this->original_price_paisa,
            'discount_paisa' => $this->discountPaisa(),
            'discount_percent' => $this->discountPercent(),
            'currency' => $this->currency,
            'billing_interval' => $this->billing_interval,
            'trial_days' => $this->trial_days,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'description' => $this->description,
            'features' => $this->whenLoaded('features', fn () => $this->features->mapWithKeys(fn (Feature $f) => [$f->key => $f->pivot?->getAttribute('value')])->all()),
            'subscriptions_count' => $this->whenCounted('subscriptions'),
        ];
    }
}
