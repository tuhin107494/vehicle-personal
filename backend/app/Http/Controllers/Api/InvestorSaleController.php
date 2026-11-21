<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvestorSaleRequest;
use App\Http\Resources\InvestorSaleResource;
use App\Models\InvestorSale;
use App\Models\Sale;
use App\Services\InvestorSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestorSaleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:sales.view', only: ['show']),
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

    /** One investor sale on its own page: the investor's and the shop's side, with the vehicle and who it was sold to. */
    public function show(InvestorSale $investorSale): JsonResponse
    {
        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'customer', 'settlements', 'sale.vehicle', 'sale.customer'])));
    }

    public function cancel(InvestorSale $investorSale): JsonResponse
    {
        $investorSale = $this->investorSales->cancel($investorSale);

        return $this->success(InvestorSaleResource::make($investorSale->load(['investor', 'settlements'])), 'Investor sale cancelled');
    }
}
