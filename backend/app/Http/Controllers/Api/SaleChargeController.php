<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleChargeRequest;
use App\Models\Sale;
use App\Models\SaleCharge;
use App\Services\SaleChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Extra money charged to a customer on a sale (a late fee, documents...). */
class SaleChargeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:sales.charge')];
    }

    public function __construct(private SaleChargeService $charges) {}

    public function store(SaleChargeRequest $request, Sale $sale): JsonResponse
    {
        $charge = $this->charges->add($sale, $request->validated(), $request->user());

        return $this->success($this->charges->values($charge) + ['id' => $charge->id], 'Charge added', 201);
    }

    public function show(SaleCharge $charge): JsonResponse
    {
        return $this->success(['values' => $this->charges->values($charge), 'edits' => $this->charges->history($charge)]);
    }

    public function update(SaleChargeRequest $request, SaleCharge $charge): JsonResponse
    {
        $charge = $this->charges->update($charge, $request->validated(), $request->user());

        return $this->success(['values' => $this->charges->values($charge->fresh()), 'edits' => $this->charges->history($charge)], 'Charge updated');
    }

    public function destroy(Request $request, SaleCharge $charge): JsonResponse
    {
        $this->charges->delete($charge, $request->user());

        return $this->success(null, 'Charge deleted');
    }
}
