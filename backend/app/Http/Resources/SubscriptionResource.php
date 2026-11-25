<?php

namespace App\Http\Resources;

use App\Models\Billing\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shop's subscription as the app shows it: the plan, its state and when it ends. Needs `plan` loaded.
 *
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->summary();
    }
}
