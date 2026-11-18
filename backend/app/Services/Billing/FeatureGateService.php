<?php

namespace App\Services\Billing;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\FeatureUsage;
use App\Models\Sale;
use App\Models\Scopes\ShopScope;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\FeatureCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Answers "may this shop use X, and how much is left?" from the cached entitlements.
 * A feature the plan does not mention is allowed and unlimited: the super admin sets 0 to switch one off.
 * Period counters (sales a month) are atomic cache increments, seeded from the database on a miss and
 * written back to feature_usage by syncUsage(); stock-like limits (staff, vehicles) are counted live.
 */
class FeatureGateService
{
    public function __construct(private EntitlementCache $entitlements) {}

    public function canUse(int $shopId, string $key): bool
    {
        $value = $this->value($shopId, $key);

        return match (FeatureCatalog::typeOf($key)) {
            FeatureCatalog::BOOLEAN => $value === null || $value === '1',
            FeatureCatalog::LIMIT => $value === null || (int) $value > 0,
            default => false,
        };
    }

    /** The cap for a limit feature, or null when there is none. */
    public function limit(int $shopId, string $key): ?int
    {
        $value = $this->value($shopId, $key);

        return $value === null ? null : (int) $value;
    }

    /** How many more the shop may add, or null when unlimited. */
    public function remaining(int $shopId, string $key): ?int
    {
        $limit = $this->limit($shopId, $key);

        return $limit === null ? null : max($limit - $this->used($shopId, $key), 0);
    }

    /** Refuses (409) when adding $count more would pass the plan's cap. Call it inside the service that creates the rows. */
    public function ensureCanAdd(int $shopId, string $key, int $count = 1): void
    {
        $remaining = $this->remaining($shopId, $key);

        if ($remaining !== null && $remaining < $count) {
            throw new BusinessRuleException('Your plan allows up to '.$this->limit($shopId, $key).' '.$this->noun($key).'. Upgrade your plan to add more.');
        }
    }

    /** Refuses (409) when the plan does not include a boolean feature. Routes use the CheckFeature middleware instead. */
    public function ensureCanUse(int $shopId, string $key): void
    {
        if (! $this->canUse($shopId, $key)) {
            throw new BusinessRuleException('Your plan does not include '.$this->noun($key).'. Upgrade your plan to use it.');
        }
    }

    /** What the shop has used of a limit in the current period. */
    public function used(int $shopId, string $key): int
    {
        return match ($key) {
            'max_staff' => User::where('shop_id', $shopId)->count(),
            'max_vehicles_in_stock' => Vehicle::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->whereIn('status', ['in_stock', 'booked'])->count(),
            default => $this->counter($shopId, $key),
        };
    }

    /** Counts one more use of a period feature (atomic, so parallel requests do not lose a count). */
    public function increment(int $shopId, string $key, int $by = 1): void
    {
        $this->counter($shopId, $key); // seeds the counter from the database when the cache has none
        Cache::increment($this->counterKey($shopId, $key), $by);
    }

    /** Writes the cached period counters to feature_usage, so the numbers survive a cache reset. */
    public function syncUsage(int $shopId): void
    {
        foreach (FeatureCatalog::all() as [$key, $type]) {
            if ($type !== FeatureCatalog::LIMIT || in_array($key, ['max_staff', 'max_vehicles_in_stock'], true)) {
                continue;
            }

            [$start, $end] = $this->period();
            FeatureUsage::withoutGlobalScope(ShopScope::class)->updateOrCreate(
                ['shop_id' => $shopId, 'feature_key' => $key, 'period_start' => $start->toDateString()],
                ['used' => $this->counter($shopId, $key), 'period_end' => $end->toDateString()],
            );
        }
    }

    private function value(int $shopId, string $key): ?string
    {
        return $this->entitlements->get($shopId)['features'][$key] ?? null;
    }

    /** The cache counter for a period feature, seeded from the database the first time. */
    private function counter(int $shopId, string $key): int
    {
        $cacheKey = $this->counterKey($shopId, $key);

        if (! Cache::has($cacheKey)) {
            Cache::add($cacheKey, $this->countFromDatabase($shopId, $key), now()->endOfMonth()->addDay());
        }

        return (int) Cache::get($cacheKey, 0);
    }

    private function countFromDatabase(int $shopId, string $key): int
    {
        [$start, $end] = $this->period();

        return match ($key) {
            'max_sales_per_month' => Sale::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->whereBetween('sale_date', [$start->toDateString(), $end->toDateString()])->count(),
            default => (int) FeatureUsage::withoutGlobalScope(ShopScope::class)->where('shop_id', $shopId)->where('feature_key', $key)->where('period_start', $start->toDateString())->value('used'),
        };
    }

    private function counterKey(int $shopId, string $key): string
    {
        return "tenant:{$shopId}:usage:{$key}:".$this->period()[0]->format('Y-m');
    }

    /** @return array{0: Carbon, 1: Carbon} the calendar month, as every period feature counts by month */
    private function period(): array
    {
        return [today()->startOfMonth(), today()->endOfMonth()];
    }

    private function noun(string $key): string
    {
        return match ($key) {
            'max_staff' => 'staff accounts',
            'max_vehicles_in_stock' => 'vehicles in stock',
            'max_sales_per_month' => 'sales a month',
            'investors' => 'investor funding',
            'installments' => 'installment sales',
            'bookings' => 'bookings',
            'reports' => 'reports',
            'customer_import' => 'customer import',
            default => str_replace('_', ' ', $key),
        };
    }
}
