<?php

namespace App\Services\Gateway\Data;

/**
 * What a callback or webhook says, after its signature was checked. It names the attempt (by our idempotency key or by the
 * gateway's payment id) so the worker can verify it with the gateway; the payload itself is never acted on.
 */
final readonly class WebhookResult
{
    public function __construct(
        public bool $valid,
        public ?string $idempotencyKey = null,
        public ?string $gatewayPaymentId = null,
        public ?string $reason = null, // why it is invalid, for the log
        public array $raw = [],
    ) {}

    public static function invalid(string $reason, array $raw = []): self
    {
        return new self(false, null, null, $reason, $raw);
    }

    public static function forAttempt(?string $idempotencyKey, ?string $gatewayPaymentId = null, array $raw = []): self
    {
        return new self(true, $idempotencyKey, $gatewayPaymentId, null, $raw);
    }
}
