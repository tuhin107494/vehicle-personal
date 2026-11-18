<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CancelSubscriptionRequest;
use App\Http\Requests\Billing\ChangePlanRequest;
use App\Http\Resources\Billing\InvoiceResource;
use App\Http\Resources\Billing\SubscriptionResource;
use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\FeatureGateService;
use App\Services\Billing\SubscriptionService;
use App\Support\FeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shop's own subscription: what it is on and how much of it is used, changing plan, and its invoices.
 * Owners only (the route group); the subscription middleware lets an expired shop reach these to renew.
 */
class BillingController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions, private BillingService $billing) {}

    /** The current plan with its usage, and the invoice waiting to be paid, if any. */
    public function subscription(Request $request, FeatureGateService $features): JsonResponse
    {
        $shopId = $request->user()->shop_id;
        $current = $this->subscriptions->current($shopId)?->load('pendingPlan');
        $usage = [];

        foreach (FeatureCatalog::all() as [$key, $type]) {
            $usage[$key] = $type === FeatureCatalog::LIMIT
                ? ['type' => $type, 'limit' => $features->limit($shopId, $key), 'used' => $features->used($shopId, $key), 'remaining' => $features->remaining($shopId, $key)]
                : ['type' => $type, 'allowed' => $features->canUse($shopId, $key)];
        }

        $open = Invoice::where('status', 'unpaid')->where('total_paisa', '>', 0)->with('plan')->latest('id')->first();

        return $this->success([
            'subscription' => $current ? SubscriptionResource::make($current) : null,
            'usage' => $usage,
            'open_invoice' => $open ? InvoiceResource::make($open) : null,
            'grace_days' => $this->subscriptions->graceDays(),
        ]);
    }

    public function subscribe(ChangePlanRequest $request): JsonResponse
    {
        $result = $this->subscriptions->subscribe($request->user()->shop, $this->plan($request), $this->billing->findCoupon($request->validated('coupon_code')));

        return $this->success($this->changed($result['subscription'], $result['invoice']), $result['invoice']->isPayable() ? 'Plan chosen. Pay the invoice to start it.' : 'Plan started', 201);
    }

    public function upgrade(ChangePlanRequest $request): JsonResponse
    {
        $result = $this->subscriptions->upgrade($request->user()->shop, $this->plan($request), $this->billing->findCoupon($request->validated('coupon_code')));

        return $this->success($this->changed($result['subscription'], $result['invoice']), $result['invoice']?->isPayable() ? 'Plan upgraded. Pay the invoice for the difference.' : 'Plan upgraded');
    }

    public function downgrade(ChangePlanRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->downgrade($request->user()->shop, $this->plan($request));

        return $this->success($this->changed($subscription->load('pendingPlan'), null), 'The plan will change when the current period ends.');
    }

    public function cancel(CancelSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->cancel($request->user()->shop, $request->boolean('immediately'));

        return $this->success($this->changed($subscription, null), $request->boolean('immediately') ? 'Subscription cancelled' : 'Renewal stopped. The plan runs until the period ends.');
    }

    public function invoices(Request $request): JsonResponse
    {
        $invoices = Invoice::with('plan')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate($this->perPage($request));

        return $this->paginated($invoices, InvoiceResource::class);
    }

    public function invoice(Invoice $invoice): JsonResponse
    {
        return $this->success(InvoiceResource::make($invoice->load(['plan', 'coupon', 'shop'])));
    }

    private function plan(ChangePlanRequest $request): Plan
    {
        return Plan::findOrFail($request->validated('plan_id'));
    }

    /** @return array{subscription: SubscriptionResource, invoice: InvoiceResource|null} */
    private function changed(Subscription $subscription, ?Invoice $invoice): array
    {
        return ['subscription' => SubscriptionResource::make($subscription), 'invoice' => $invoice ? InvoiceResource::make($invoice->load('plan')) : null];
    }
}
