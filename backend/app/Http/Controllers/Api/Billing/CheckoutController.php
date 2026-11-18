<?php

namespace App\Http\Controllers\Api\Billing;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\InitiatePaymentRequest;
use App\Http\Requests\Gateway\ManualReferenceRequest;
use App\Http\Resources\Gateway\GatewayOptionResource;
use App\Http\Resources\Gateway\PaymentAttemptResource;
use App\Models\Billing\Invoice;
use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\PaymentAttempt;
use App\Services\Gateway\CheckoutService;
use App\Services\Gateway\GatewayRegistry;
use App\Services\Gateway\Gateways\ManualGateway;
use App\Support\Photos;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** A shop paying its own invoices. Route-model binding is shop-scoped, so another shop's invoice or attempt is a 404. Owners only (routes). */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout) {}

    public function gateways(Invoice $invoice): JsonResponse
    {
        return $this->success(GatewayOptionResource::collection($this->checkout->available($invoice)));
    }

    public function pay(InitiatePaymentRequest $request, Invoice $invoice): JsonResponse
    {
        ['attempt' => $attempt, 'result' => $result] = $this->checkout->initiate($invoice, $request->validated('gateway'), $request->validated('idempotency_key'), $request->user());

        return $this->success(PaymentAttemptResource::make($attempt)->additional(['initiation' => $result?->toArray()]), 'Payment started', 201);
    }

    public function show(PaymentAttempt $attempt): JsonResponse
    {
        return $this->success(PaymentAttemptResource::make($attempt->load('invoice')));
    }

    /** Bank transfer: the shop says it has paid, with the reference (and the receipt when required). The platform confirms it later. */
    public function submitReference(ManualReferenceRequest $request, PaymentAttempt $attempt, GatewayRegistry $registry): JsonResponse
    {
        if ($attempt->gateway_code !== ManualGateway::CODE) {
            throw new BusinessRuleException('Only a bank transfer takes a reference.');
        }

        if (! $attempt->isOpen()) {
            throw new BusinessRuleException('This payment is already '.$attempt->status.'.');
        }

        /** @var ManualGateway $gateway */
        $gateway = $registry->makeByCode(ManualGateway::CODE);

        if ($gateway->proofRequired() && ! $request->hasFile('proof') && ! $attempt->proof_path) {
            throw new BusinessRuleException('Attach a photo of the transfer receipt.');
        }

        $path = $request->hasFile('proof') ? Photos::store($request->file('proof'), 'transfer-proofs') : $attempt->proof_path;

        DB::transaction(function () use ($attempt, $request, $path) {
            $attempt = PaymentAttempt::lockForUpdate()->findOrFail($attempt->id);
            $attempt->update(['gateway_payment_id' => $request->validated('reference'), 'proof_path' => $path, 'submitted_at' => now(), 'status' => 'pending', 'expires_at' => null]);
            GatewayLog::create(['shop_id' => $attempt->shop_id, 'attempt_id' => $attempt->id, 'gateway_code' => ManualGateway::CODE, 'direction' => 'request', 'action' => 'reference_submitted', 'payload' => ['reference' => $request->validated('reference'), 'note' => $request->validated('note')]]);
        });

        return $this->success(PaymentAttemptResource::make($attempt->fresh()), 'Reference submitted. We will confirm the transfer soon.');
    }
}
