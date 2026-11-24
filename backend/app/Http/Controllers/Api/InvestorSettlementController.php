<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvestorSettlementRequest;
use App\Http\Resources\InvestorSettlementResource;
use App\Models\InvestorSettlement;
use App\Services\InvestorSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvestorSettlementController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:settlements.view', only: ['index', 'show']),
            new Middleware('permission:settlements.manage', only: ['store', 'update', 'destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $settlements = InvestorSettlement::query()
            ->with('investor')
            ->when($request->investor_id, fn ($q, $v) => $q->where('investor_id', $v))
            ->when($request->type, fn ($q, $v) => $q->where('type', $v))
            ->latest('date')->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($settlements, InvestorSettlementResource::class);
    }

    public function store(InvestorSettlementRequest $request, InvestorSaleService $investorSales): JsonResponse
    {
        $settlement = $request->filled('investor_sale_id')
            ? $investorSales->pay($request->validated(), $request->user())
            : InvestorSettlement::create($request->validated() + ['created_by' => $request->user()->id]);

        return $this->success(InvestorSettlementResource::make($settlement), 'Settlement recorded', 201);
    }

    public function show(InvestorSettlement $settlement): JsonResponse
    {
        return $this->success(InvestorSettlementResource::make($settlement->load('investor')));
    }

    public function update(InvestorSettlementRequest $request, InvestorSettlement $settlement, InvestorSaleService $investorSales): JsonResponse
    {
        $this->refuseAutomatic($settlement);

        if ($settlement->investor_sale_id) {
            // a payment on an investor sale stays on it, with the same checks as a new one
            $settlement = $investorSales->updatePayment($settlement, $request->validated());
        } elseif ($request->filled('investor_sale_id')) {
            throw new BusinessRuleException('A settlement cannot be moved onto an investor sale. Delete it and record the payment again.');
        } else {
            $settlement->update($request->validated());
        }

        return $this->success(InvestorSettlementResource::make($settlement), 'Settlement updated');
    }

    public function destroy(InvestorSettlement $settlement, InvestorSaleService $investorSales): JsonResponse
    {
        $this->refuseAutomatic($settlement);
        $settlement->investor_sale_id ? $investorSales->deletePayment($settlement) : $settlement->delete();

        return $this->success(null, 'Settlement deleted');
    }

    /** The settlements the system made change with the sale or collection that made them, so the investor's figures stay right. */
    private function refuseAutomatic(InvestorSettlement $settlement): void
    {
        if ($settlement->isAutomatic()) {
            throw new BusinessRuleException('This settlement was made by the system from a sale or a collection. Change that sale or payment instead.');
        }
    }
}
