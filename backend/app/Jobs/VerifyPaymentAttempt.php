<?php

namespace App\Jobs;

use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\GatewayHealth;
use App\Services\Gateway\GatewayRegistry;
use App\Services\Gateway\PaymentVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks the gateway what became of an attempt and applies the answer. Queued by webhooks, callbacks and reconciliation;
 * the payload a webhook carried is never trusted, only this call to the gateway's API is.
 */
class VerifyPaymentAttempt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @return list<int> seconds before each retry: 10 s, 30 s, 1 min, 5 min */
    public function backoff(): array
    {
        return [10, 30, 60, 300];
    }

    public function __construct(public int $attemptId)
    {
        $this->onQueue('payments');
    }

    public function handle(GatewayRegistry $registry, PaymentVerifier $verifier, GatewayHealth $health): void
    {
        $attempt = PaymentAttempt::withoutGlobalScope(ShopScope::class)->find($this->attemptId);

        if (! $attempt || $attempt->isFinal()) {
            return;
        }

        try {
            $result = $registry->makeByCode($attempt->gateway_code)->verify($attempt);
        } catch (\Throwable $e) {
            $health->recordFailure($attempt->gateway_code);
            GatewayLog::create(['shop_id' => $attempt->shop_id, 'attempt_id' => $attempt->id, 'gateway_code' => $attempt->gateway_code, 'direction' => 'error', 'action' => 'verify', 'payload' => ['message' => $e->getMessage()]]);
            throw $e; // the queue retries with backoff
        }

        $verifier->apply($attempt, $result);
        PublishOutbox::dispatch();
    }
}
