<?php

namespace App\Services\Gateway;

use App\Jobs\VerifyPaymentAttempt;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\LedgerEntry;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Gateway\ReconciliationMismatch;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\Gateways\ManualGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Catches what webhooks missed. Every few minutes, attempts that have waited too long are asked about again through
 * the gateway's API (the same job a webhook queues); once a day, yesterday's payments are checked against the gateway
 * and the ledger, and anything that does not match is flagged for the super admin. Nothing here changes a payment.
 */
class ReconciliationService
{
    public const BATCH = 1000;

    public const PENDING_MINUTES = 10;

    public function __construct(private GatewayRegistry $registry) {}

    /** Queues a verification for every open attempt older than PENDING_MINUTES; bank transfers wait for a person instead. Returns how many were queued. */
    public function reconcilePending(?Carbon $at = null): int
    {
        $at ??= now();
        $queued = 0;

        PaymentAttempt::withoutGlobalScope(ShopScope::class)
            ->whereIn('status', PaymentAttempt::OPEN)
            ->where('gateway_code', '!=', ManualGateway::CODE)
            ->where('created_at', '<=', $at->copy()->subMinutes(self::PENDING_MINUTES))
            ->chunkById(self::BATCH, function ($rows) use (&$queued) {
                foreach ($rows as $attempt) {
                    VerifyPaymentAttempt::dispatch($attempt->id);
                    $queued++;
                }
            });

        return $queued;
    }

    /**
     * Compares every payment recorded on a day with the gateway's own record and with the ledger.
     *
     * @return array{checked: int, flagged: int, unreachable: int}
     */
    public function compareSettlements(Carbon $day): array
    {
        $totals = ['checked' => 0, 'flagged' => 0, 'unreachable' => 0];

        GatewayPayment::withoutGlobalScope(ShopScope::class)
            ->whereBetween('paid_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->with('attempt')
            ->chunkById(self::BATCH, function ($rows) use (&$totals) {
                foreach ($rows as $payment) {
                    $totals['checked']++;
                    $totals['flagged'] += $this->checkLedger($payment) ? 1 : 0;

                    if ($payment->gateway_code === ManualGateway::CODE) {
                        continue; // a bank transfer has no API to ask
                    }

                    $flagged = $this->checkGateway($payment);
                    $totals['flagged'] += $flagged === true ? 1 : 0;
                    $totals['unreachable'] += $flagged === null ? 1 : 0;
                }
            });

        return $totals;
    }

    /** The ledger must carry the payment as a debit to the gateway account for exactly its amount, and balance. */
    private function checkLedger(GatewayPayment $payment): bool
    {
        $entries = LedgerEntry::withoutGlobalScope(ShopScope::class)->where('reference_type', 'gateway_payment')->where('reference_id', $payment->id)->get();
        $received = (int) $entries->where('account', LedgerEntry::gatewayAccount($payment->gateway_code))->sum('debit_paisa');
        $debits = (int) $entries->sum('debit_paisa');
        $credits = (int) $entries->sum('credit_paisa');

        if ($received === $payment->amount_paisa && $debits === $credits) {
            return false;
        }

        return (bool) ReconciliationMismatch::flag(
            $payment, ReconciliationMismatch::KIND_LEDGER,
            $debits !== $credits ? 'The ledger entries for this payment do not balance.' : 'The ledger does not carry this payment for its full amount.',
            $payment->amount_paisa, $received, ['debits_paisa' => $debits, 'credits_paisa' => $credits, 'entries' => $entries->count()],
        );
    }

    /** True when the gateway disagrees and it was flagged, false when it agrees, null when it could not be asked. */
    private function checkGateway(GatewayPayment $payment): ?bool
    {
        try {
            $result = $this->registry->makeByCode($payment->gateway_code)->queryStatus($payment->gateway_trx_id);
        } catch (\Throwable $e) {
            Log::warning('Settlement check could not reach the gateway', ['shop_id' => $payment->shop_id, 'gateway_payment_id' => $payment->id, 'gateway' => $payment->gateway_code, 'error' => $e->getMessage()]);

            return null;
        }

        if ($result->status !== VerificationResult::SUCCESS) {
            return (bool) ReconciliationMismatch::flag($payment, ReconciliationMismatch::KIND_STATUS, 'The gateway no longer reports this payment as completed: '.($result->message ?? $result->status).'.', $payment->amount_paisa, null, $result->raw);
        }

        $currency = strtoupper((string) ($payment->attempt->currency ?? 'BDT'));

        if ($result->amountPaisa !== $payment->amount_paisa || strtoupper((string) $result->currency) !== $currency) {
            return (bool) ReconciliationMismatch::flag($payment, ReconciliationMismatch::KIND_AMOUNT, sprintf('The gateway reports %s %s, the payment was recorded as %s %s.', $result->currency, $result->amountPaisa, $currency, $payment->amount_paisa), $payment->amount_paisa, $result->amountPaisa, $result->raw);
        }

        return false;
    }
}
