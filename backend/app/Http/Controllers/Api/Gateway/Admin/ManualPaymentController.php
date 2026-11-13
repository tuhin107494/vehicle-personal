<?php

namespace App\Http\Controllers\Api\Gateway\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\RejectAttemptRequest;
use App\Http\Resources\Gateway\PaymentAttemptResource;
use App\Jobs\PublishOutbox;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\Gateways\ManualGateway;
use App\Services\Gateway\PaymentVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Bank transfers waiting for the platform's word. Super admin only; the approval is applied exactly like a gateway's own answer. */
class ManualPaymentController extends Controller
{
    public function __construct(private PaymentVerifier $verifier) {}

    /** The platform sees every shop: the scope is lifted on purpose (the route group is role:super_admin), on the attempt and on its invoice. */
    private function withInvoice(): array
    {
        return ['invoice' => fn ($q) => $q->withoutGlobalScope(ShopScope::class)->with('shop')];
    }

    public function index(Request $request): JsonResponse
    {
        $attempts = PaymentAttempt::withoutGlobalScope(ShopScope::class)->with($this->withInvoice())
            ->where('gateway_code', ManualGateway::CODE)
            ->when($request->status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->where('status', 'pending')->whereNotNull('submitted_at'))
            ->latest('submitted_at')->latest('id')->paginate($this->perPage($request));

        return $this->paginated($attempts, PaymentAttemptResource::class);
    }

    public function approve(Request $request, int $attempt): JsonResponse
    {
        $attempt = $this->pendingTransfer($attempt);
        $reference = $attempt->gateway_payment_id ?: 'MANUAL-'.$attempt->id;

        $attempt = $this->verifier->apply($attempt, VerificationResult::success($reference, $attempt->amount_paisa, $attempt->currency, ['approved_by' => $request->user()->id]));
        AdminAuditLog::record($request->user(), 'payment.approved', $attempt, [['field' => 'reference', 'to' => $reference]], $request->ip());
        PublishOutbox::dispatch();

        return $this->success(PaymentAttemptResource::make($attempt->load($this->withInvoice())), 'Transfer approved');
    }

    public function reject(RejectAttemptRequest $request, int $attempt): JsonResponse
    {
        $attempt = $this->pendingTransfer($attempt);

        $attempt = $this->verifier->apply($attempt, VerificationResult::failed('Rejected: '.$request->validated('reason')));
        AdminAuditLog::record($request->user(), 'payment.rejected', $attempt, [['field' => 'reason', 'to' => $request->validated('reason')]], $request->ip());

        return $this->success(PaymentAttemptResource::make($attempt->load($this->withInvoice())), 'Transfer rejected');
    }

    private function pendingTransfer(int $id): PaymentAttempt
    {
        $attempt = PaymentAttempt::withoutGlobalScope(ShopScope::class)->where('gateway_code', ManualGateway::CODE)->findOrFail($id);

        if ($attempt->isFinal()) {
            throw new BusinessRuleException('This transfer was already '.$attempt->status.'.');
        }

        return $attempt;
    }
}
