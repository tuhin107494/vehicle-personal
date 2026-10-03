<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvestorSettlementRequest;
use App\Http\Resources\InvestorSettlementResource;
use App\Models\InvestorSettlement;
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
            ->paginate($request->integer('per_page', 15));

        return $this->paginated($settlements, InvestorSettlementResource::class);
    }

    public function store(InvestorSettlementRequest $request): JsonResponse
    {
        $settlement = InvestorSettlement::create($request->validated() + ['created_by' => $request->user()->id]);

        return $this->success(InvestorSettlementResource::make($settlement), 'Settlement recorded', 201);
    }

    public function show(InvestorSettlement $settlement): JsonResponse
    {
        return $this->success(InvestorSettlementResource::make($settlement->load('investor')));
    }

    public function update(InvestorSettlementRequest $request, InvestorSettlement $settlement): JsonResponse
    {
        $settlement->update($request->validated());

        return $this->success(InvestorSettlementResource::make($settlement), 'Settlement updated');
    }

    public function destroy(InvestorSettlement $settlement): JsonResponse
    {
        $settlement->delete();

        return $this->success(null, 'Settlement deleted');
    }
}
