<?php

namespace App\Http\Middleware;

use App\Services\Billing\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shop routes: a shop whose trial or subscription has ended is read-only until the super admin extends it.
 * Viewing and exporting (GET) still work; creating, editing and deleting are refused. Nothing is deleted.
 */
class EnsureActiveSubscription
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $this->subscriptions->locked($request->user()?->shop)) {
            abort(403, SubscriptionService::ENDED_MESSAGE);
        }

        return $next($request);
    }
}
