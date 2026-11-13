<?php

namespace App\Services\Gateway;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\Invoice;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\PaymentAttempt;
use App\Models\User;
use App\Services\Gateway\Data\InitiationResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A shop paying an invoice: which gateways it may use, and starting an attempt. One idempotency key per click on Pay,
 * so a double click (or a retried request) comes back with the same attempt instead of a second charge.
 */
class CheckoutService
{
    public const ATTEMPT_MINUTES = 30;

    public function __construct(private GatewayRegistry $registry, private GatewaySettingsService $settings, private GatewayHealth $health) {}

    /** Gateways offered for an invoice: active, configured for their mode, within their amount limits, and not failing right now. In sort order. */
    public function available(Invoice $invoice): Collection
    {
        return GatewayConfig::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (GatewayConfig $g) => GatewayRegistry::knows($g->code) && $this->settings->isConfigured($g) && $g->accepts($invoice->total_paisa) && ! $this->health->isOpen($g->code))
            ->values();
    }

    /**
     * Starts (or returns) the attempt for an invoice through a gateway.
     *
     * @return array{attempt: PaymentAttempt, result: InitiationResult|null}
     */
    public function initiate(Invoice $invoice, string $code, string $idempotencyKey, User $user): array
    {
        $existing = PaymentAttempt::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            if ($existing->invoice_id !== $invoice->id) {
                throw new BusinessRuleException('This payment key was used for another invoice.');
            }

            // the same click again: nothing new is started
            return ['attempt' => $existing, 'result' => $existing->isOpen() ? $this->registry->makeByCode($existing->gateway_code)->initiate($existing) : null];
        }

        $gateway = $this->available($invoice)->firstWhere('code', $code);

        if (! $gateway) {
            throw new BusinessRuleException('This payment method is not available for this invoice.');
        }

        $attempt = DB::transaction(function () use ($invoice, $gateway, $idempotencyKey, $user) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isPayable()) {
                throw new BusinessRuleException('This invoice is not open for payment.');
            }

            return PaymentAttempt::create([
                'shop_id' => $invoice->shop_id, 'invoice_id' => $invoice->id, 'gateway_code' => $gateway->code,
                'amount_paisa' => $invoice->total_paisa, 'currency' => $invoice->currency, 'idempotency_key' => $idempotencyKey,
                'status' => 'initiated', 'expires_at' => now()->addMinutes(self::ATTEMPT_MINUTES), 'created_by' => $user->id,
            ]);
        });

        try {
            $result = $this->registry->make($gateway)->initiate($attempt);
        } catch (\Throwable $e) {
            // the gateway could not even start: the attempt is closed as failed, the breaker counts it, and the payer can pick another gateway
            $attempt->update(['status' => 'failed', 'failure_reason' => 'Could not start the payment: '.$e->getMessage()]);
            $this->health->recordFailure($gateway->code);
            GatewayLog::create(['shop_id' => $attempt->shop_id, 'attempt_id' => $attempt->id, 'gateway_code' => $gateway->code, 'direction' => 'error', 'action' => 'initiate', 'payload' => ['message' => $e->getMessage()]]);
            throw new BusinessRuleException('The payment could not be started. Try again or choose another payment method.');
        }

        $attempt->update(['status' => 'pending', 'gateway_payment_id' => $result->gatewayPaymentId ?? $attempt->gateway_payment_id]);

        return ['attempt' => $attempt->fresh(), 'result' => $result];
    }
}
