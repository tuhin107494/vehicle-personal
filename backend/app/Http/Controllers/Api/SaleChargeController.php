<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function store(Request $request, Sale $sale): JsonResponse
    {
        $charge = $this->charges->add($sale, $this->validated($request), $request->user());

        return $this->success($this->charges->values($charge) + ['id' => $charge->id], 'Charge added', 201);
    }

    public function show(SaleCharge $charge): JsonResponse
    {
        return $this->success(['values' => $this->charges->values($charge), 'edits' => $this->charges->history($charge)]);
    }

    public function update(Request $request, SaleCharge $charge): JsonResponse
    {
        $charge = $this->charges->update($charge, $this->validated($request), $request->user());

        return $this->success(['values' => $this->charges->values($charge->fresh()), 'edits' => $this->charges->history($charge)], 'Charge updated');
    }

    public function destroy(Request $request, SaleCharge $charge): JsonResponse
    {
        $this->charges->delete($charge, $request->user());

        return $this->success(null, 'Charge deleted');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'purpose' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1'],
            'charged_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'purpose.required' => 'Enter what the charge is for.',
            'amount.required' => 'Enter the amount.',
            'amount.min' => 'Amount must be greater than zero.',
            'charged_on.before_or_equal' => 'Date cannot be in the future.',
        ]);
    }
}
