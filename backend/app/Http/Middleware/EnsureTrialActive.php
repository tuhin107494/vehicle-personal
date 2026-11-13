<?php

namespace App\Http\Middleware;

use App\Services\TrialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Shop data routes: a shop whose free trial has ended is locked until the super admin extends it. */
class EnsureTrialActive
{
    public function __construct(private TrialService $trial) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->trial->ended($request->user()?->shop)) {
            abort(403, TrialService::ENDED_MESSAGE);
        }

        return $next($request);
    }
}
