<?php

namespace Tests\Support;

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
 * A redirect gateway for tests: one secret credential, a scripted verify() answer, and a webhook that is valid when
 * `sig` is "ok". Mirrors the shape of bKash / Nagad / SSLCommerz without any network.
 */
class FakeGateway implements PaymentGatewayInterface
{
    public const CODE = 'fake';

    /** What verify() and queryStatus() answer next; set by the test. */
    public static ?VerificationResult $next = null;

    /** Every attempt verify() was asked about, so a test can count calls. */
    public static array $verified = [];

    public static bool $failInitiate = false;

    public function __construct(private GatewayConfig $config) {}

    public static function code(): string
    {
        return self::CODE;
    }

    public static function fields(): array
    {
        return [
            CredentialField::url('base_url', 'Base URL'),
            CredentialField::secret('app_secret', 'App secret'),
        ];
    }

    public static function credentialSchema(): array
    {
        return array_map(fn (CredentialField $f) => $f->toArray(), self::fields());
    }

    public function initiate(PaymentAttempt $attempt): InitiationResult
    {
        if (self::$failInitiate) {
            throw new \RuntimeException('Gateway down');
        }

        return InitiationResult::redirect(($this->config->credentials()['base_url'] ?? 'https://fake.test').'/pay/'.$attempt->idempotency_key, 'GW-'.$attempt->id);
    }

    public function verify(PaymentAttempt $attempt): VerificationResult
    {
        self::$verified[] = $attempt->id;

        return self::$next ?? VerificationResult::pending();
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        return $request->input('sig') === 'ok'
            ? WebhookResult::forAttempt($request->input('key'), $request->input('gw_id'), $request->all())
            : WebhookResult::invalid('Bad signature', $request->all());
    }

    public function refund(GatewayPayment $payment, int $amountPaisa, string $reason): string
    {
        return 'REF-'.$payment->id;
    }

    public function queryStatus(string $gatewayTrxId): VerificationResult
    {
        return self::$next ?? VerificationResult::pending();
    }

    public function testConnection(): void
    {
        if (($this->config->credentials()['app_secret'] ?? null) !== 'good-secret') {
            throw new \RuntimeException('Invalid app secret');
        }
    }

    public static function reset(): void
    {
        self::$next = null;
        self::$verified = [];
        self::$failInitiate = false;
    }
}
