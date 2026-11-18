<?php

namespace App\Services\Billing;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Scopes\ShopScope;
use App\Models\User;
use App\Support\FeatureCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The super admin's plans: the plan row and its feature values change together, every change is audited, and the
 * shops on the plan get their cached entitlements cleared so the new limits apply at once.
 */
class PlanService
{
    public function __construct(private EntitlementCache $entitlements) {}

    /**
     * @param  array<string, mixed>  $data  the plan's own fields
     * @param  array<string, mixed>  $features  key => value ("1"/"0" or true/false for a switch, a number or null for a limit)
     */
    public function save(?Plan $plan, array $data, array $features, User $admin, ?string $ip = null): Plan
    {
        return DB::transaction(function () use ($plan, $data, $features, $admin, $ip) {
            $plan = $plan ? Plan::lockForUpdate()->findOrFail($plan->id) : new Plan;
            $before = $plan->exists ? $plan->only(array_keys($data)) + ['features' => $this->values($plan)] : [];

            $plan->fill($data + ['slug' => $plan->slug ?: Str::slug($data['name'] ?? '')]);
            $plan->save();

            $this->syncFeatures($plan, $features);
            $after = $plan->only(array_keys($data)) + ['features' => $this->values($plan)];
            $changes = [];

            foreach ($after as $field => $value) {
                if (($before[$field] ?? null) !== $value) {
                    $changes[] = ['field' => $field, 'from' => $before[$field] ?? null, 'to' => $value];
                }
            }

            AdminAuditLog::record($admin, $before ? 'plan.updated' : 'plan.created', $plan, $changes, $ip);

            // the shops on this plan see the new limits on their next request
            DB::afterCommit(fn () => Subscription::withoutGlobalScope(ShopScope::class)->where('plan_id', $plan->id)
                ->whereIn('status', Subscription::USABLE)->pluck('shop_id')->unique()->each(fn (int $shopId) => $this->entitlements->forget($shopId)));

            return $plan->fresh('features');
        });
    }

    /** Writes the plan_features rows: a value for every known key that was sent; a key left out keeps what it had. */
    private function syncFeatures(Plan $plan, array $features): void
    {
        $ids = Feature::whereIn('key', array_keys($features))->pluck('id', 'key');
        $rows = [];

        foreach ($features as $key => $value) {
            if (! isset($ids[$key])) {
                continue;
            }

            $rows[$ids[$key]] = ['value' => $this->normalise($key, $value)];
        }

        $plan->features()->syncWithoutDetaching($rows);
    }

    private function normalise(string $key, mixed $value): ?string
    {
        if (FeatureCatalog::typeOf($key) === FeatureCatalog::BOOLEAN) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        return $value === null || $value === '' ? null : (string) (int) $value;
    }

    /** @return array<string, string|null> */
    private function values(Plan $plan): array
    {
        return $plan->features()->get()->mapWithKeys(fn (Feature $f) => [$f->key => $f->pivot?->getAttribute('value')])->sortKeys()->all();
    }
}
