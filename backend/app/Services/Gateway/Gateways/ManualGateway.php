<?php

namespace App\Services\Gateway\Gateways;

use App\Exceptions\BusinessRuleException;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\PaymentAttempt;
use App\Services\Gateway\Data\CredentialField;
use App\Services\Gateway\Data\InitiationResult;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\Data\WebhookResult;
use App\Services\Gateway\PaymentGatewayInterface;
use Illuminate\Http\Request;

/**
 * Bank transfer. The shop sees the bank details, pays outside the app and submits the transfer reference (and a proof
 * image when the super admin asks for one). Nothing is confirmed until the super admin approves it from the admin panel,
 * which the verifier then applies like any gateway's answer. There is no webhook and no refund API.
 */
class ManualGateway implements PaymentGatewayInterface
{
    public const CODE = 'manual';

    public function __construct(private GatewayConfig $config) {}

    public static function code(): string
    {
        return self::CODE;
    }

    public static function fields(): array
    {
        return [
            CredentialField::text('bank_name', 'Bank name'),
            CredentialField::text('account_name', 'Account name'),
            CredentialField::text('account_number', 'Account number'),
            CredentialField::text('branch', 'Branch', false),
            CredentialField::text('routing_number', 'Routing number', false),
            CredentialField::longText('instructions', 'Payment instructions', false, 'Shown to the shop with the bank details. Plain text.'),
            CredentialField::boolean('proof_required', 'Proof upload required', 'The shop must attach a photo of the receipt or transfer slip.'),
        ];
    }

    public static function credentialSchema(): array
    {
        return array_map(fn (CredentialField $f) => $f->toArray(), self::fields());
    }

    public function initiate(PaymentAttempt $attempt): InitiationResult
    {
        $c = $this->config->credentials();
        $details = array_filter([
            'Bank' => $c['bank_name'] ?? null, 'Account name' => $c['account_name'] ?? null, 'Account number' => $c['account_number'] ?? null,
            'Branch' => $c['branch'] ?? null, 'Routing number' => $c['routing_number'] ?? null,
            'Reference to write' => $attempt->idempotency_key,
        ]);

        return InitiationResult::instructions($c['instructions'] ?? 'Transfer the amount to the account below and submit the transfer reference.', $details);
    }

    /** A transfer is only ever confirmed by a person: until then the gateway's answer is "still waiting". */
    public function verify(PaymentAttempt $attempt): VerificationResult
    {
        return VerificationResult::pending('Waiting for the platform to confirm the transfer.');
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        return WebhookResult::invalid('Bank transfers have no webhook.');
    }

    public function refund(GatewayPayment $payment, int $amountPaisa, string $reason): string
    {
        throw new BusinessRuleException('A bank transfer is refunded by hand; record it as a manual refund.');
    }

    public function queryStatus(string $gatewayTrxId): VerificationResult
    {
        return VerificationResult::pending();
    }

    public function testConnection(): void
    {
        foreach (['bank_name', 'account_name', 'account_number'] as $field) {
            if (blank($this->config->credentials()[$field] ?? null)) {
                throw new BusinessRuleException('Fill in the bank name, account name and account number.');
            }
        }
    }

    public function proofRequired(): bool
    {
        return (bool) ($this->config->credentials()['proof_required'] ?? false);
    }
}
