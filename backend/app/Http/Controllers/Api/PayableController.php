<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdvanceRequest;
use App\Http\Requests\StorePurchasePaymentRequest;
use App\Models\Advance;
use App\Models\Purchase;
use App\Services\PayableService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PayableController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:purchases.view', only: ['index', 'advances', 'purchasePayments']),
            new Middleware('permission:purchases.edit', only: ['pay', 'storeAdvance']),
        ];
    }

    public function __construct(private PayableService $payables) {}

    /** Purchases that are not paid in full, with each supplier's advance that could be used on them. */
    public function index(): JsonResponse
    {
        $purchases = Purchase::with(['party', 'vehicle'])->whereRaw('price - paid_amount - due_adjustment > 0')->orderBy('purchase_date')->orderBy('id')->limit(1000)->get();
        $advance = Advance::selectRaw('party_id, SUM(amount - used) as left_amount')->groupBy('party_id')->pluck('left_amount', 'party_id');

        return $this->success($purchases->map(fn (Purchase $p) => [
            'id' => $p->id,
            'purchase_date' => $p->purchase_date->toDateString(),
            'party' => $p->party->only(['id', 'name', 'phone', 'alt_phone']),
            'vehicle' => $p->vehicle->only(['id', 'chassis_no', 'brand', 'model']),
            'source' => $p->source,
            'price' => $p->price,
            'paid_amount' => $p->paid_amount,
            'due_adjustment' => $p->due_adjustment,
            'balance' => $this->payables->balance($p),
            'advance_available' => round((float) ($advance[$p->party_id] ?? 0), 2),
        ])->values());
    }

    public function pay(StorePurchasePaymentRequest $request, Purchase $purchase): JsonResponse
    {
        $payment = $this->payables->pay($purchase, $request->validated(), $request->user());

        return $this->success([
            'payment' => $payment,
            'balance' => $this->payables->balance($purchase->fresh()),
        ], 'Payment recorded', 201);
    }

    public function purchasePayments(Purchase $purchase): JsonResponse
    {
        return $this->success($purchase->payments()->orderBy('paid_at')->orderBy('id')->get());
    }

    public function advances(): JsonResponse
    {
        return $this->success(Advance::with('party:id,name,phone')->orderByDesc('paid_at')->orderByDesc('id')->limit(200)->get());
    }

    public function storeAdvance(StoreAdvanceRequest $request): JsonResponse
    {
        $advance = $this->payables->addAdvance($request->validated(), $request->user());

        return $this->success($advance->load('party:id,name,phone'), 'Advance recorded', 201);
    }
}
