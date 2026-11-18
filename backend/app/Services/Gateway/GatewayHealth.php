<?php

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Cache;

/**
 * A circuit breaker per gateway: after a run of failed calls the gateway is hidden at checkout for a while, instead of
 * sending every payer into the same error. Counted in the cache, so every worker and web process sees the same state.
 */
class GatewayHealth
{
    public const FAILURES_TO_OPEN = 5;

    public const OPEN_SECONDS = 300;

    public function recordFailure(string $code): void
    {
        $failures = (int) Cache::increment($this->key($code, 'failures'));
        Cache::put($this->key($code, 'failures'), $failures, now()->addMinutes(10)); // a run of failures within ten minutes

        if ($failures >= self::FAILURES_TO_OPEN) {
            Cache::put($this->key($code, 'open'), now()->toIso8601String(), self::OPEN_SECONDS);
        }
    }

    public function recordSuccess(string $code): void
    {
        Cache::forget($this->key($code, 'failures'));
        Cache::forget($this->key($code, 'open'));
    }

    /** Whether the gateway is currently held back from checkout. */
    public function isOpen(string $code): bool
    {
        return Cache::has($this->key($code, 'open'));
    }

    private function key(string $code, string $what): string
    {
        return "gateway:{$code}:{$what}";
    }
}
