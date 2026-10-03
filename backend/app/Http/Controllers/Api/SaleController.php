<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelSaleRequest;
use App\Http\Requests\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['index', 'show']),
            new Middleware('permission:sales.create', only: ['store']),
            new Middleware('permission:sales.cancel', only: ['cancel']),
        ];
    }

    private const DETAIL = [
        'vehicle', 'party', 'investor', 'purchase', 'creator', 'plan.installments',
        'payments.receiver', 'promises', 'registrationLogs.user',
    ];

    public function __construct(private SaleService $sales) {}

    public function index(Request $request): JsonResponse
    {
        $sales = Sale::query()
            ->with(['vehicle', 'party', 'plan', 'investor'])
            ->when($request->boolean('with_investor'), fn ($q) => $q->whereNotNull('investor_id'))
            ->when($request->investor_id, fn ($q, $v) => $q->where('investor_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->boolean('due'), fn ($q) => $q->where('status', 'active')->where('due_amount', '>', 0))
            ->when($request->payment_type, fn ($q, $v) => $q->where('payment_type', $v))
            ->when($request->payment_status, fn ($q, $v) => $q->where('payment_status', $v))
            ->when($request->registration_status, fn ($q, $v) => $q->where('registration_status', $v))
            ->when($request->party_id, fn ($q, $v) => $q->where('party_id', $v))
            ->when($request->vehicle_id, fn ($q, $v) => $q->where('vehicle_id', $v))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->whereHas('vehicle', fn ($q) => $q->where('chassis_no', 'like', "%$s%")->orWhere('model', 'like', "%$s%"))
                ->orWhereHas('party', fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%"))))
            ->latest('sale_date')->latest('id')
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($sales, SaleResource::class);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $sale = $this->sales->create($request->validated(), $request->user());

        return $this->success(SaleResource::make($sale->load(self::DETAIL)), 'Sale created', 201);
    }

    public function show(Sale $sale): JsonResponse
    {
        return $this->success(SaleResource::make($sale->load(self::DETAIL)));
    }

    public function cancel(CancelSaleRequest $request, Sale $sale): JsonResponse
    {
        $sale = $this->sales->cancel($sale, $request->reason, (float) $request->input('refund_amount', 0), $request->user());

        return $this->success(SaleResource::make($sale->load(self::DETAIL)), 'Sale cancelled');
    }
}
