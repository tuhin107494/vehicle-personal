<?php

namespace App\Services\Gateway;

use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\PaymentAttempt;
use App\Services\Gateway\Data\CredentialField;
use App\Services\Gateway\Data\InitiationResult;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\Data\WebhookResult;
use Illuminate\Http\Request;

/**
 * What every gateway (bKash, Nagad, SSLCommerz, bank transfer, and any added later) can do. Business code talks only
 * to this interface, through GatewayRegistry; it never branches on a gateway's name. A gateway reads its credentials
 * from the GatewayConfig it is given, never from code or .env.
 */
interface PaymentGatewayInterface
{
    /** The code the registry and the gateway_configs row use, e.g. "bkash". */
    public static function code(): string;

    /** The settings this gateway needs, for the super admin's configure form. */
    public static function credentialSchema(): array;

    public function __construct(GatewayConfig $config);

    /** Starts a payment for an attempt: where to send the user, or what to tell them. Must not change the attempt. */
    public function initiate(PaymentAttempt $attempt): InitiationResult;

    /** Asks the gateway itself what became of an attempt. The only source of truth for marking a payment paid. */
    public function verify(PaymentAttempt $attempt): VerificationResult;

    /** Reads a callback or webhook: checks its signature and says which attempt it is about. Must not touch the database. */
    public function handleWebhook(Request $request): WebhookResult;

    /** Sends money back. Returns the gateway's refund id. */
    public function refund(GatewayPayment $payment, int $amountPaisa, string $reason): string;

    /** The gateway's view of a transaction by its own id, for reconciliation. */
    public function queryStatus(string $gatewayTrxId): VerificationResult;

    /** A safe call (fetching a token, pinging the API) that proves the saved credentials work. Throws with the gateway's message when they do not. */
    public function testConnection(): void;

    /** @return list<CredentialField> */
    public static function fields(): array;
}
