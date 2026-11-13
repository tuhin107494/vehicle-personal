<?php

namespace App\Services\Gateway\Data;

/** The gateway's own answer about a payment. The verifier trusts nothing else: amount and currency are checked against the invoice. */
final readonly class VerificationResult
{
    public const SUCCESS = 'success';

    public const PENDING = 'pending';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public function __construct(
        public string $status,
        public ?string $gatewayTrxId = null,
        public ?int $amountPaisa = null,
        public ?string $currency = null,
        public ?string $message = null,
        public ?int $feePaisa = null,
        public array $raw = [],
    ) {}

    public static function success(string $trxId, int $amountPaisa, string $currency = 'BDT', array $raw = [], ?int $feePaisa = null): self
    {
        return new self(self::SUCCESS, $trxId, $amountPaisa, $currency, null, $feePaisa, $raw);
    }

    public static function pending(?string $message = null, array $raw = []): self
    {
        return new self(self::PENDING, null, null, null, $message, null, $raw);
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(self::FAILED, null, null, null, $message, null, $raw);
    }

    public static function cancelled(string $message = 'Cancelled by the payer.', array $raw = []): self
    {
        return new self(self::CANCELLED, null, null, null, $message, null, $raw);
    }

    public static function expired(string $message = 'The payment window closed.', array $raw = []): self
    {
        return new self(self::EXPIRED, null, null, null, $message, null, $raw);
    }

    public function isFinal(): bool
    {
        return $this->status !== self::PENDING;
    }
}
