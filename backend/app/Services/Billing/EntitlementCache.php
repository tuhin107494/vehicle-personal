<?php

namespace App\Services\Billing;

use App\Models\Billing\Subscription;
use App\Models\Scopes\ShopScope;
use Illuminate\Support\Facades\Cache;

/**
 * A shop's resolved plan, kept in the cache so a request does not read subscriptions and plan_features every time.
 * Any subscription change calls forget(); the next read rebuilds it.
 *
 * @phpstan-type Entitlements array{status: string, subscription_id: int|null, plan_id: int|null, plan: string|null, ends_at: string|null, features: array<string, string|null>}
 */
class EntitlementCache
{
    private const TTL_SECONDS = 3600;

    public static function key(int $shopId): string
    {
        return "tenant:{$shopId}:entitlements";
    }

    /** @return Entitlements */
    public function get(int $shopId): array
    {
        return Cache::remember(self::key($shopId), self::TTL_SECONDS, fn () => $this->resolve($shopId));
    }

    public function forget(int $shopId): void
    {
        Cache::forget(self::key($shopId));
    }

    /** @return Entitlements */
    private function resolve(int $shopId): array
    {
        // runs from requests, workers and the scheduler alike, so the shop is named here instead of relying on a signed-in user
        $subscription = Subscription::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)
            ->with('plan.features')->latest('id')->first();

        if (! $subscription) {
            return ['status' => 'none', 'subscription_id' => null, 'plan_id' => null, 'plan' => null, 'ends_at' => null, 'features' => []];
        }

        $features = [];

        foreach ($subscription->plan->features as $feature) {
            $features[$feature->key] = $feature->pivot?->getAttribute('value');
        }

        return [
            'status' => $subscription->status,
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'plan' => $subscription->plan->slug,
            'ends_at' => $subscription->ends_at->toIso8601String(),
            'features' => $features,
        ];
    }
}
