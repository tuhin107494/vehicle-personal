<?php

namespace App\Http\Controllers\Api\Billing\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ExtendSubscriptionRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Scopes\ShopScope;
use App\Models\Shop;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Every shop's subscription, super admin only. The shop scope is lifted on purpose: this is the platform's view (the route group is role:super_admin). */
class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

    /** Each shop's latest subscription, with the counts per status for the filter chips. */
    public function index(Request $request): JsonResponse
    {
        $latest = Subscription::withoutGlobalScope(ShopScope::class)->selectRaw('MAX(id)')->groupBy('shop_id');
        $base = Subscription::withoutGlobalScope(ShopScope::class)->whereIn('id', $latest);

        $rows = (clone $base)->with(['shop', 'plan', 'pendingPlan'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->plan_id, fn ($q, $p) => $q->where('plan_id', $p))
            ->when($request->search, fn ($q, $s) => $q->whereHas('shop', fn ($shop) => $shop->where('name', 'like', "%$s%")))
            ->orderBy('ends_at')->paginate($this->perPage($request));

        return $this->paginated($rows, SubscriptionResource::class, 'OK', ['status_counts' => (clone $base)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all()]);
    }

    /** One shop: its current subscription and every invoice. */
    public function show(Shop $shop): JsonResponse
    {
        $current = $this->subscriptions->current($shop->id)?->load(['shop', 'pendingPlan']);
        $invoices = Invoice::withoutGlobalScope(ShopScope::class)->where('shop_id', $shop->id)->with('plan')->latest('id')->limit(50)->get();

        return $this->success([
            'shop' => $shop->only(['id', 'name', 'phone', 'is_active']),
            'subscription' => $current ? SubscriptionResource::make($current) : null,
            'invoices' => InvoiceResource::collection($invoices),
        ]);
    }

    public function extend(ExtendSubscriptionRequest $request, Shop $shop): JsonResponse
    {
        $plan = $request->validated('plan_id') ? Plan::findOrFail($request->validated('plan_id')) : null;
        $subscription = $this->subscriptions->extendManually($shop, Carbon::parse($request->validated('ends_at'))->endOfDay(), $request->user(), $request->validated('note'), $plan);

        return $this->success(SubscriptionResource::make($subscription->load(['shop', 'pendingPlan'])), 'Subscription extended');
    }
}
