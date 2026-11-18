<?php

namespace App\Http\Controllers\Api\Billing\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PlanRequest;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Billing\Plan;
use App\Services\Billing\PlanService;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;

/** Plans and the feature catalog, super admin only (the route group). A plan is never deleted: it is switched off. */
class PlanController extends Controller
{
    public function __construct(private PlanService $plans) {}

    public function index(): JsonResponse
    {
        $plans = Plan::with('features')->withCount('subscriptions')->orderBy('sort_order')->orderBy('id')->get();

        return $this->success(PlanResource::collection($plans), 'OK', 200, ['features' => array_map(fn (array $f) => ['key' => $f[0], 'type' => $f[1], 'description' => $f[2]], FeatureCatalog::all())]);
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = $this->plans->save(null, $this->fields($request), $request->validatedFeatures(), $request->user(), $request->ip());

        return $this->success(PlanResource::make($plan), 'Plan created', 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return $this->success(PlanResource::make($plan->load('features')->loadCount('subscriptions')));
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        $plan = $this->plans->save($plan, $this->fields($request), $request->validatedFeatures(), $request->user(), $request->ip());

        return $this->success(PlanResource::make($plan), 'Plan updated');
    }

    /** @return array<string, mixed> */
    private function fields(PlanRequest $request): array
    {
        $data = $request->safe()->except('features');
        $data['currency'] = strtoupper($data['currency'] ?? 'BDT');
        $data['trial_days'] = (int) ($data['trial_days'] ?? 0);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        // a regular price equal to the price is no discount at all: kept empty so no "Save 0%" label shows
        $original = $data['original_price_paisa'] ?? null;
        $data['original_price_paisa'] = $original !== null && (int) $original > (int) $data['price_paisa'] ? (int) $original : null;

        return $data;
    }
}
