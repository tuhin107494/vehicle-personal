<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvestorSaleRequest;
use App\Http\Requests\UpdateInvestorSaleRequest;
use App\Http\Resources\InvestorSaleResource;
use App\Models\InvestorSale;
use App\Models\Sale;
use App\Services\InvestorSaleService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestorSaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['show']),
            new Middleware('permission:sales.edit', only: ['update']),
            new Middleware('permission:settlements.manage', only: ['dues']),
            new Middleware('permission:sales.create', only: ['store']),
            new Middleware('permission:sales.cancel', only: ['cancel']),
        ];
    }

    public function __construct(private InvestorSaleService $investorSales) {}

    public function store(InvestorSaleRequest $request, Sale $sale): JsonResponse
    {
        $investorSale = $this->investorSales->create($sale, $request->validated(), $request->user());

        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'customer', 'settlements'])), 'Investor sale created', 201);
    }

    /**
     * The active investor sales on which the investor still has to give the shop part of their money (to collect it),
     * optionally of one investor or matching a search (investor, chassis or buyer).
     */
    public function dues(Request $request): JsonResponse
    {
        $request->validate(['investor_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);

        $rows = InvestorSale::where('status', 'active')
            ->with(['investor', 'settlements', 'sale.vehicle', 'sale.customer'])
            ->withSum(['settlements as given' => fn ($q) => $q->where('type', 'investment')], 'amount')
            ->when($request->investor_id, fn ($q, $id) => $q->where('investor_id', $id))
            ->when($request->search, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereHas('investor', fn ($q) => $q->where('name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%"))
                ->orWhereHas('sale.vehicle', fn ($q) => $q->where('chassis_no', 'like', "%$term%")->orWhere('engine_no', 'like', "%$term%"))
                ->orWhereHas('sale.customer', fn ($q) => $q->where('name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%"))))
            ->orderBy('date')->orderBy('id')->get()
            ->filter(fn (InvestorSale $x) => $x->investorAmount() > Money::paisa($x->given ?? 0))
            ->values();

        return $this->success(InvestorSaleResource::collection($rows));
    }

    /** One investor sale on its own page: the investor's and the shop's side, with the vehicle and who it was sold to. */
    public function show(InvestorSale $investorSale): JsonResponse
    {
        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'customer', 'settlements', 'sale.vehicle', 'sale.customer'])));
    }

    public function update(UpdateInvestorSaleRequest $request, InvestorSale $investorSale): JsonResponse
    {
        $investorSale = $this->investorSales->update($investorSale, $request->validated());

        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'customer', 'settlements', 'sale.vehicle', 'sale.customer'])), 'Investor sale updated');
    }

    public function cancel(InvestorSale $investorSale): JsonResponse
    {
        $investorSale = $this->investorSales->cancel($investorSale);

        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'settlements'])), 'Investor sale cancelled');
    }
}
