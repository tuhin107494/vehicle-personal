<?php

namespace App\Services\Gateway;

use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\LedgerEntry;
use App\Models\Gateway\OutboxEvent;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\Data\VerificationResult;
use Illuminate\Support\Facades\DB;

/**
 * Applies a gateway's answer to an attempt, in one transaction under a row lock. Statuses only move forward, so a late
 * failure never overwrites a success and two workers verifying the same attempt produce exactly one payment.
 * A success writes the payment, balanced ledger entries and a PaymentSucceeded outbox event; the subscription module
 * hears about it only through that event.
 */
class PaymentVerifier
{
    public function __construct(private GatewayHealth $health) {}

    /** Returns the attempt as it is after the answer was applied (or found already final). */
    public function apply(PaymentAttempt $attempt, VerificationResult $result): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $result) {
            // workers and webhooks have no signed-in user: the attempt is named by id, not by the shop scope
            $attempt = PaymentAttempt::withoutGlobalScope(ShopScope::class)->lockForUpdate()->with('invoice')->findOrFail($attempt->id);

            if ($attempt->isFinal()) {
                return $attempt; // a repeated webhook or a second worker: the first answer stands
            }

            if ($result->status === VerificationResult::PENDING) {
                if ($attempt->expires_at?->isPast() && $attempt->gateway_code !== 'manual') {
                    $attempt->update(['status' => 'expired', 'failure_reason' => 'No answer from the gateway before the payment window closed.']);
                }

                return $attempt;
            }

            if ($result->status !== VerificationResult::SUCCESS) {
                $attempt->update(['status' => $result->status, 'failure_reason' => $result->message, 'verified_at' => now()]);

                return $attempt;
            }

            // the gateway says paid: the amount and currency must be exactly what the invoice asked for
            if ($result->amountPaisa !== $attempt->amount_paisa || strtoupper((string) $result->currency) !== strtoupper($attempt->currency)) {
                $attempt->update(['status' => 'failed', 'verified_at' => now(), 'failure_reason' => sprintf('Amount mismatch: the gateway reports %s %s, the invoice is %s %s.', $result->currency, $result->amountPaisa, $attempt->currency, $attempt->amount_paisa)]);

                return $attempt;
            }

            // the same gateway transaction may already be on another attempt (a replayed webhook with a new key): one payment per transaction
            if (GatewayPayment::withoutGlobalScope(ShopScope::class)->where('gateway_code', $attempt->gateway_code)->where('gateway_trx_id', $result->gatewayTrxId)->exists()) {
                $attempt->update(['status' => 'failed', 'verified_at' => now(), 'failure_reason' => 'This gateway transaction was already recorded.']);

                return $attempt;
            }

            $attempt->update(['status' => 'success', 'verified_at' => now(), 'gateway_payment_id' => $attempt->gateway_payment_id ?? $result->gatewayTrxId]);

            $config = GatewayConfig::where('code', $attempt->gateway_code)->first();
            $fee = $result->feePaisa ?? $config?->feeFor($attempt->amount_paisa) ?? 0;

            $payment = GatewayPayment::create([
                'attempt_id' => $attempt->id, 'invoice_id' => $attempt->invoice_id, 'shop_id' => $attempt->shop_id, 'gateway_code' => $attempt->gateway_code,
                'gateway_trx_id' => (string) $result->gatewayTrxId, 'amount_paisa' => $attempt->amount_paisa, 'fee_paisa' => $fee, 'status' => 'success', 'paid_at' => now(),
            ]);

            $this->post($payment, $fee);

            OutboxEvent::record(OutboxEvent::PAYMENT_SUCCEEDED, [
                'payment_id' => $payment->id, 'attempt_id' => $attempt->id, 'invoice_id' => $attempt->invoice_id, 'shop_id' => $attempt->shop_id,
                'amount_paisa' => $attempt->amount_paisa, 'gateway' => $attempt->gateway_code,
            ]);

            DB::afterCommit(fn () => $this->health->recordSuccess($attempt->gateway_code));

            return $attempt;
        });
    }

    /** Double entry: the gateway owes us the amount (debit), revenue is earned (credit); the fee is a cost against what the gateway owes. */
    private function post(GatewayPayment $payment, int $fee): void
    {
        $rows = [
            [LedgerEntry::gatewayAccount($payment->gateway_code), $payment->amount_paisa, 0, 'Payment received'],
            [LedgerEntry::ACCOUNT_REVENUE, 0, $payment->amount_paisa, 'Subscription revenue'],
        ];

        if ($fee > 0) {
            $rows[] = [LedgerEntry::ACCOUNT_FEES, $fee, 0, 'Gateway fee'];
            $rows[] = [LedgerEntry::gatewayAccount($payment->gateway_code), 0, $fee, 'Gateway fee'];
        }

        foreach ($rows as [$account, $debit, $credit, $memo]) {
            LedgerEntry::create(['shop_id' => $payment->shop_id, 'account' => $account, 'debit_paisa' => $debit, 'credit_paisa' => $credit, 'reference_type' => 'gateway_payment', 'reference_id' => $payment->id, 'memo' => $memo]);
        }
    }
}
