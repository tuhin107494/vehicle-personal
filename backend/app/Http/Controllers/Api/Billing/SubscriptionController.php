<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ExtendSubscriptionRequest;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;

/** Platform level: only the super admin extends a shop's subscription. */
class SubscriptionController extends Controller
{
    public function extend(ExtendSubscriptionRequest $request, Shop $shop, SubscriptionService $subscriptions): JsonResponse
    {
        $subscriptions->extend($shop, $request->integer('days'));

        return $this->success(ShopResource::make($shop->load('owner', 'subscription.plan')), 'Subscription extended');
    }
}
