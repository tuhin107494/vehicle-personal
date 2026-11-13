<?php

namespace App\Services\Gateway\Data;

/** What happens after a payment is started: send the user somewhere, or show them instructions (bank transfer). */
final readonly class InitiationResult
{
    public function __construct(
        public string $kind, // redirect, instructions
        public ?string $redirectUrl = null,
        public ?string $gatewayPaymentId = null, // the gateway's own id for this payment, kept on the attempt
        public ?string $instructions = null,
        /** @var array<string, string> label => value, e.g. bank account details */
        public array $details = [],
        public array $raw = [],
    ) {}

    public static function redirect(string $url, ?string $gatewayPaymentId = null, array $raw = []): self
    {
        return new self('redirect', $url, $gatewayPaymentId, null, [], $raw);
    }

    public static function instructions(string $text, array $details = []): self
    {
        return new self('instructions', null, null, $text, $details);
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'redirect_url' => $this->redirectUrl, 'instructions' => $this->instructions, 'details' => $this->details];
    }
}
