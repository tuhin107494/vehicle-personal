<?php

namespace App\Http\Middleware;

use App\Services\Billing\FeatureGateService;
use App\Support\FeatureCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `feature:<key>` on a route: the shop's plan must include that feature. Limits are checked in the services, not here. */
class CheckFeature
{
    public function __construct(private FeatureGateService $gate) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        if (! in_array($key, FeatureCatalog::keys(), true)) {
            abort(500, "Unknown feature key [$key]."); // a typo in routes/api.php, caught by the first test that hits the route
        }

        $shopId = $request->user()?->shop_id;

        if ($shopId && ! $this->gate->canUse($shopId, $key)) {
            abort(402, 'Your plan does not include this feature. Upgrade your plan to use it.');
        }

        return $next($request);
    }
}
