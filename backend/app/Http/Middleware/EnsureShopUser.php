<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shop data routes: the user must belong to an active shop. */
class EnsureShopUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->shop_id) {
            abort(403, 'This area is only for shop users.');
        }

        if (! $user->shop?->is_active) {
            abort(403, 'This shop is currently disabled.');
        }

        return $next($request);
    }
}
