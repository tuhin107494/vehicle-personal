<?php

namespace App\Http\Controllers\Api\Gateway\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gateway\RefundRequest;
use App\Http\Resources\Gateway\GatewayPaymentResource;
use App\Http\Resources\Gateway\GatewayRefundResource;
use App\Jobs\PublishOutbox;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\GatewayRefund;
use App\Models\Scopes\ShopScope;
use App\Services\Gateway\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Confirmed payments and refunds across every shop, super admin only (the route group lifts the scope on purpose). */
class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $payments = GatewayPayment::withoutGlobalScope(ShopScope::class)->with(['shop', 'invoice' => fn ($q) => $q->withoutGlobalScope(ShopScope::class)])
            ->when($request->gateway, fn ($q, $g) => $q->where('gateway_code', $g))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->from, fn ($q, $d) => $q->where('paid_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->to, fn ($q, $d) => $q->where('paid_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('gateway_trx_id', 'like', "%$s%")->orWhereHas('shop', fn ($shop) => $shop->where('name', 'like', "%$s%"))))
            ->latest('paid_at')->latest('id')->paginate($this->perPage($request));

        return $this->paginated($payments, GatewayPaymentResource::class);
    }

    public function show(int $payment): JsonResponse
    {
        $payment = GatewayPayment::withoutGlobalScope(ShopScope::class)->with(['shop', 'invoice' => fn ($q) => $q->withoutGlobalScope(ShopScope::class), 'refunds.requester'])->findOrFail($payment);

        return $this->success(GatewayPaymentResource::make($payment));
    }

    public function refund(RefundRequest $request, int $payment, RefundService $refunds): JsonResponse
    {
        $payment = GatewayPayment::withoutGlobalScope(ShopScope::class)->findOrFail($payment);
        $refund = $refunds->refund($payment, (int) $request->validated('amount_paisa'), $request->validated('reason'), $request->user(), $request->boolean('offline'), $request->ip());
        PublishOutbox::dispatch();

        return $this->success(GatewayRefundResource::make($refund->load(['requester', 'payment'])), 'Refund recorded', 201);
    }

    public function refunds(Request $request): JsonResponse
    {
        $refunds = GatewayRefund::with(['shop', 'requester', 'payment'])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate($this->perPage($request));

        return $this->paginated($refunds, GatewayRefundResource::class);
    }
}
