<?php

namespace App\Http\Middleware;

use App\Models\Billing\Subscription;
use App\Services\Billing\EntitlementCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop whose subscription has run out may still look (GET) and reach its billing pages, but cannot change anything.
 * A shop with no subscription at all is not held back: enforcement starts once it has been put on a plan.
 */
class EnsureActiveSubscription
{
    public function __construct(private EntitlementCache $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        $shopId = $request->user()?->shop_id;

        if (! $shopId) {
            return $next($request);
        }

        $status = $this->entitlements->get($shopId)['status'];

        if ($status === 'none' || in_array($status, Subscription::USABLE, true)) {
            return $next($request);
        }

        if ($request->isMethodSafe() || $request->is('api/billing', 'api/billing/*')) {
            return $next($request);
        }

        abort(402, $status === 'cancelled'
            ? 'Your subscription was cancelled. Choose a plan to continue making changes.'
            : 'Your subscription has expired. Renew it to continue making changes.');
    }
}
