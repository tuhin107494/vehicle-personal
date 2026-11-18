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
use App\Services\Gateway\GatewayHttp;
use App\Services\Gateway\GatewaySettingsService;
use App\Services\Gateway\PaymentGatewayInterface;
use App\Support\Money;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * bKash Tokenized Checkout, which bKash recommends as "the standard way to accept payments today"
 * (developer.bka.sh/docs/product-overview). Flow: token (cached, refreshed) → create payment → the payer approves on
 * bKash → callback → execute → query to confirm. Only execute/query answers mark a payment paid; the callback only
 * names the payment.
 *
 * Two API versions, chosen by the saved base URL (one with "/v2" is v2):
 *  - v2, the current docs: developer.bka.sh/docs (grant-token, create-payment, execute-payment, query-payment,
 *    search-transaction, refund). JSON fields are paymentId / trxId; success answers carry no statusCode, errors carry
 *    externalCode + errorMessageEn.
 *  - v1.2.0-beta, as bKash's own WooCommerce plugin uses it (github.com/bKash-developer/bKash-for-woocommerce,
 *    includes/classes/ApiComm.php). Fields are paymentID / trxID; answers carry statusCode "0000" on success.
 * Agreements (saved wallets) are not implemented: payments are checkout without agreement.
 */
class BkashGateway implements PaymentGatewayInterface
{
    public const CODE = 'bkash';

    /** Per version: endpoint paths relative to the base URL, and the request field names that differ. */
    private const API = [
        'v1' => [
            'grant' => '/tokenized/checkout/token/grant', 'refresh' => '/tokenized/checkout/token/refresh',
            'create' => '/tokenized/checkout/create', 'execute' => '/tokenized/checkout/execute', 'query' => '/tokenized/checkout/payment/status',
            'search' => '/tokenized/checkout/general/searchTransaction', 'refund' => '/tokenized/checkout/payment/refund',
            'paymentId' => 'paymentID', 'trxId' => 'trxID', 'refundAmount' => 'amount',
        ],
        'v2' => [
            'grant' => '/auth/grant-token', 'refresh' => '/auth/refresh-token',
            'create' => '/payment/create', 'execute' => '/payment/execute', 'query' => '/query/payment',
            'search' => '/general/search-transaction', 'refund' => '/refund/payment/transaction',
            'paymentId' => 'paymentId', 'trxId' => 'trxId', 'refundAmount' => 'refundAmount',
        ],
    ];

    /** The only transactionStatus that means paid for intent "sale". A cancelled or failed payment stays "Initiated" (query-payment docs). */
    private const DONE = 'Completed';

    /** Not documented as coming back from bKash; kept so a final answer, if bKash ever sends one, is final. */
    private const FINAL_FAILURES = ['Failed' => VerificationResult::FAILED, 'Rejected' => VerificationResult::FAILED, 'Cancelled' => VerificationResult::CANCELLED, 'Expired' => VerificationResult::EXPIRED];

    /** The token lasts 3600 s; bKash asks for a refresh "between the 50th and 55th minute" (token-managment-v2). */
    private const TOKEN_MINUTES = 50;

    /** The refresh token lasts 30 days; it is kept a day less. */
    private const REFRESH_DAYS = 29;

    public function __construct(private GatewayConfig $config) {}

    public static function code(): string
    {
        return self::CODE;
    }

    public static function fields(): array
    {
        return [
            CredentialField::url('base_url', 'Base URL', true, 'v2 sandbox: https://tokenized.sandbox.bka.sh/v2/tokenized-checkout · v1.2.0-beta sandbox: https://tokenized.sandbox.bka.sh/v1.2.0-beta (live: tokenized.pay.bka.sh). The version follows this URL; bKash tells you which one your account uses.'),
            CredentialField::text('username', 'Username'),
            CredentialField::secret('password', 'Password'),
            CredentialField::secret('app_key', 'App key'),
            CredentialField::secret('app_secret', 'App secret'),
        ];
    }

    public static function credentialSchema(): array
    {
        return array_map(fn (CredentialField $f) => $f->toArray(), self::fields());
    }

    public function initiate(PaymentAttempt $attempt): InitiationResult
    {
        $body = [
            'payerReference' => (string) $attempt->shop_id,
            'callbackURL' => app(GatewaySettingsService::class)->urls(self::CODE)['callback'],
            'amount' => self::taka($attempt->amount_paisa),
            'currency' => $attempt->currency,
            'intent' => 'sale',
            'merchantInvoiceNumber' => $attempt->idempotency_key,
        ];
        // v1 names the kind of payment in the body (0011 = checkout without agreement); v2 has no mode field
        $answer = $this->call($attempt, 'create', $this->version() === 'v1' ? ['mode' => '0011'] + $body : $body);
        $paymentId = self::pick($answer, 'paymentID', 'paymentId');

        if (self::failed($answer) || blank($answer['bkashURL'] ?? null) || blank($paymentId)) {
            throw new BusinessRuleException('bKash could not create the payment: '.(self::message($answer) ?? 'no answer').'.');
        }

        return InitiationResult::redirect($answer['bkashURL'], $paymentId, $answer);
    }

    /** The callback carries paymentID, status and a signature (both versions); none is trusted, verify() asks bKash. */
    public function handleWebhook(Request $request): WebhookResult
    {
        $paymentId = $request->input('paymentID') ?? $request->input('paymentId');

        if (blank($paymentId)) {
            return WebhookResult::invalid('No paymentID in the callback.', $request->all());
        }

        return WebhookResult::forAttempt(null, (string) $paymentId, $request->all());
    }

    /**
     * Query first (safe to repeat); when the payer has approved but the payment is not executed yet, execute it,
     * then trust only a Completed answer with a transaction id. Anything unclear stays pending until the attempt's
     * window closes.
     */
    public function verify(PaymentAttempt $attempt): VerificationResult
    {
        if (blank($attempt->gateway_payment_id)) {
            return VerificationResult::pending('No bKash paymentID on this attempt.');
        }

        $ref = [$this->field('paymentId') => $attempt->gateway_payment_id];
        $query = $this->call($attempt, 'query', $ref);
        $status = $query['transactionStatus'] ?? null;

        if ($status === self::DONE && filled(self::pick($query, 'trxID', 'trxId'))) {
            return $this->completed($query);
        }

        if (isset(self::FINAL_FAILURES[$status])) {
            return new VerificationResult(self::FINAL_FAILURES[$status], null, null, null, 'bKash: '.$status, null, $query);
        }

        // execute completes an approved payment; a repeat on a completed one returns an error, which the query above catches first
        $execute = $this->call($attempt, 'execute', $ref);

        if (($execute['transactionStatus'] ?? null) === self::DONE && filled(self::pick($execute, 'trxID', 'trxId'))) {
            return $this->completed($execute);
        }

        if (isset(self::FINAL_FAILURES[$execute['transactionStatus'] ?? ''])) {
            return new VerificationResult(self::FINAL_FAILURES[$execute['transactionStatus']], null, null, null, 'bKash: '.$execute['transactionStatus'], null, $execute);
        }

        return VerificationResult::pending(self::message($execute) ?? self::message($query) ?? 'Not completed yet.', $execute ?: $query);
    }

    public function refund(GatewayPayment $payment, int $amountPaisa, string $reason): string
    {
        $http = new GatewayHttp(self::CODE, $payment->shop_id, $payment->attempt_id);
        $answer = $this->body($http->post('refund', $this->url('refund'), [
            $this->field('paymentId') => $payment->attempt?->gateway_payment_id,
            $this->field('trxId') => $payment->gateway_trx_id,
            $this->field('refundAmount') => self::taka($amountPaisa),
            'sku' => 'subscription',
            'reason' => mb_substr($reason, 0, 255),
        ], $this->authHeaders()));
        $refundId = self::pick($answer, 'refundTrxID', 'refundTrxId');

        if (self::failed($answer) || blank($refundId)) {
            throw new BusinessRuleException('bKash refused the refund: '.(self::message($answer) ?? 'no answer').'.');
        }

        return $refundId;
    }

    public function queryStatus(string $gatewayTrxId): VerificationResult
    {
        $http = new GatewayHttp(self::CODE);
        $answer = $this->body($http->post('search', $this->url('search'), [$this->field('trxId') => $gatewayTrxId], $this->authHeaders()));

        return ($answer['transactionStatus'] ?? null) === self::DONE
            ? $this->completed($answer + ['trxID' => $gatewayTrxId])
            : VerificationResult::pending(self::message($answer), $answer);
    }

    /**
     * A token proves username, password, app key and app secret together. A cached one is reused: bKash blocks the app
     * for an hour after a third grant within an hour, and saving changed credentials already clears the cache, so a
     * cached token always belongs to the credentials saved now.
     */
    public function testConnection(): void
    {
        $this->token();
    }

    /** Which API the saved base URL points at. */
    private function version(): string
    {
        return str_contains((string) ($this->config->credentials()['base_url'] ?? ''), '/v2') ? 'v2' : 'v1';
    }

    private function field(string $name): string
    {
        return self::API[$this->version()][$name];
    }

    private function url(string $action): string
    {
        return rtrim((string) ($this->config->credentials()['base_url'] ?? ''), '/').self::API[$this->version()][$action];
    }

    /** @param  array<string, mixed>  $payload */
    private function call(PaymentAttempt $attempt, string $action, array $payload): array
    {
        return $this->body((new GatewayHttp(self::CODE, $attempt->shop_id, $attempt->id))->post($action, $this->url($action), $payload, $this->authHeaders()));
    }

    private function completed(array $body): VerificationResult
    {
        return VerificationResult::success(
            (string) self::pick($body, 'trxID', 'trxId'),
            Money::paisa($body['amount'] ?? 0),
            (string) ($body['currency'] ?? 'BDT'),
            $body,
        );
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        // the raw id_token in Authorization, no "Bearer" prefix, and the app key in X-App-Key (both versions' samples)
        return ['Authorization' => $this->token(), 'X-App-Key' => (string) ($this->config->credentials()['app_key'] ?? '')];
    }

    /**
     * The id_token: cached for 50 minutes; after that the refresh token gets a new one, and only without a working
     * refresh token is a new grant asked for. bKash allows two grant/refresh calls an hour, so one process at a time
     * fetches a token (a lock in the cache) and the others wait for its answer.
     */
    private function token(): string
    {
        $cached = Cache::get($this->tokenKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return Cache::lock($this->tokenKey().':lock', 30)->block(15, function () {
            $cached = Cache::get($this->tokenKey()); // another process may have fetched it while this one waited

            return is_string($cached) && $cached !== '' ? $cached : $this->fetchToken();
        });
    }

    private function fetchToken(): string
    {
        $c = $this->config->credentials();
        $headers = ['username' => (string) ($c['username'] ?? ''), 'password' => (string) ($c['password'] ?? '')];
        $keys = ['app_key' => $c['app_key'] ?? '', 'app_secret' => $c['app_secret'] ?? ''];
        $http = new GatewayHttp(self::CODE);
        $refresh = Cache::get($this->refreshKey());
        $answer = [];

        if (is_string($refresh) && $refresh !== '') {
            $answer = $this->body($http->post('refresh_token', $this->url('refresh'), $keys + ['refresh_token' => $refresh], $headers));
        }

        if (blank($answer['id_token'] ?? null)) {
            $answer = $this->body($http->post('grant_token', $this->url('grant'), $keys, $headers));
        }

        if (blank($answer['id_token'] ?? null)) {
            throw new BusinessRuleException('bKash did not issue a token: '.(self::message($answer) ?? 'check the username, password, app key and app secret').'.');
        }

        Cache::put($this->tokenKey(), $answer['id_token'], now()->addMinutes(self::TOKEN_MINUTES));

        if (filled($answer['refresh_token'] ?? null)) {
            Cache::put($this->refreshKey(), $answer['refresh_token'], now()->addDays(self::REFRESH_DAYS));
        }

        return $answer['id_token'];
    }

    /** Cleared by GatewaySettingsService::forgetTokens() whenever the credentials change. */
    private function tokenKey(): string
    {
        return 'gateway:'.self::CODE.':token:'.$this->config->active_mode;
    }

    private function refreshKey(): string
    {
        return $this->tokenKey().':refresh';
    }

    /** An error answer: v2 sends externalCode (+ errorMessageEn); v1 sends a statusCode other than "0000". */
    private static function failed(array $body): bool
    {
        return filled($body['externalCode'] ?? null) || (isset($body['statusCode']) && $body['statusCode'] !== '0000');
    }

    private static function message(array $body): ?string
    {
        return $body['errorMessageEn'] ?? $body['statusMessage'] ?? null;
    }

    /** The first of two spellings of a field that is present (v1 paymentID / trxID, v2 paymentId / trxId). */
    private static function pick(array $body, string $first, string $second): ?string
    {
        $value = $body[$first] ?? $body[$second] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function body(Response $response): array
    {
        return is_array($response->json()) ? $response->json() : [];
    }

    private static function taka(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', '');
    }
}
