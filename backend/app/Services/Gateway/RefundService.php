<?php

namespace App\Services\Gateway;

use App\Exceptions\BusinessRuleException;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\GatewayRefund;
use App\Models\Gateway\LedgerEntry;
use App\Models\Gateway\OutboxEvent;
use App\Models\Scopes\ShopScope;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Money going back to a shop. The refund row is written first (pending), the gateway is asked outside any transaction,
 * and only its answer marks the refund done: the payment's refunded total, balanced ledger entries and an outbox event
 * all change together. A refund made outside the gateway (money sent back by hand) is recorded the same way, as "offline".
 */
class RefundService
{
    public function __construct(private GatewayRegistry $registry) {}

    public function refund(GatewayPayment $payment, int $amountPaisa, string $reason, User $admin, bool $offline = false, ?string $ip = null): GatewayRefund
    {
        $refund = DB::transaction(function () use ($payment, $amountPaisa, $reason, $admin) {
            $payment = GatewayPayment::withoutGlobalScope(ShopScope::class)->lockForUpdate()->findOrFail($payment->id);

            if ($amountPaisa > $payment->refundablePaisa()) {
                throw new BusinessRuleException('Only '.Money::format($payment->refundablePaisa()).' of this payment can still be refunded.');
            }

            if (GatewayRefund::where('gateway_payment_id', $payment->id)->where('status', 'pending')->exists()) {
                throw new BusinessRuleException('A refund of this payment is already in progress.');
            }

            return GatewayRefund::create(['gateway_payment_id' => $payment->id, 'shop_id' => $payment->shop_id, 'amount_paisa' => $amountPaisa, 'reason' => $reason, 'requested_by' => $admin->id]);
        });

        if ($offline) {
            $gatewayRefundId = 'OFFLINE-'.$refund->id;
        } else {
            try {
                $gatewayRefundId = $this->registry->makeByCode($payment->gateway_code)->refund($payment->load('attempt'), $amountPaisa, $reason);
            } catch (\Throwable $e) {
                $refund->update(['status' => 'failed', 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
                GatewayLog::create(['shop_id' => $payment->shop_id, 'attempt_id' => $payment->attempt_id, 'gateway_code' => $payment->gateway_code, 'direction' => 'error', 'action' => 'refund', 'payload' => ['message' => $e->getMessage(), 'refund_id' => $refund->id]]);

                throw new BusinessRuleException('The gateway refused the refund: '.$e->getMessage());
            }
        }

        return DB::transaction(function () use ($payment, $refund, $gatewayRefundId, $amountPaisa, $reason, $admin, $offline, $ip) {
            $payment = GatewayPayment::withoutGlobalScope(ShopScope::class)->lockForUpdate()->findOrFail($payment->id);
            $refund->update(['status' => 'success', 'gateway_refund_id' => $gatewayRefundId]);

            // the total is recounted from the refund rows, never added to
            $refunded = (int) GatewayRefund::where('gateway_payment_id', $payment->id)->where('status', 'success')->sum('amount_paisa');
            $payment->update(['refunded_paisa' => $refunded, 'status' => $refunded >= $payment->amount_paisa ? 'refunded' : 'partially_refunded']);

            foreach ([[LedgerEntry::ACCOUNT_REFUNDS, $amountPaisa, 0], [LedgerEntry::gatewayAccount($payment->gateway_code), 0, $amountPaisa]] as [$account, $debit, $credit]) {
                LedgerEntry::create(['shop_id' => $payment->shop_id, 'account' => $account, 'debit_paisa' => $debit, 'credit_paisa' => $credit, 'reference_type' => 'gateway_refund', 'reference_id' => $refund->id, 'memo' => $offline ? 'Refund (offline)' : 'Refund']);
            }

            OutboxEvent::record(OutboxEvent::PAYMENT_REFUNDED, ['refund_id' => $refund->id, 'payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id, 'shop_id' => $payment->shop_id, 'amount_paisa' => $amountPaisa]);
            AdminAuditLog::record($admin, 'refund.requested', $refund, [['field' => 'amount_paisa', 'to' => $amountPaisa], ['field' => 'reason', 'to' => $reason], ['field' => 'offline', 'to' => $offline]], $ip);

            return $refund->fresh();
        });
    }
}
