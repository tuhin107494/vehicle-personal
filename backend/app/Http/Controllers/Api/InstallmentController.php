<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstallmentResource;
use App\Models\Installment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InstallmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:collections.view', only: ['index']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $installments = Installment::query()
            ->with(['plan.sale.vehicle', 'plan.sale.customer', 'plan.sale.plan'])
            ->when($request->status === 'overdue', fn ($q) => $q->where(fn ($q) => $q
                ->where('status', 'overdue')
                ->orWhere(fn ($q) => $q->whereIn('status', ['pending', 'partial'])->whereDate('due_date', '<', today()))))
            ->when($request->status && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('unpaid'), fn ($q) => $q->whereIn('status', ['pending', 'partial', 'overdue']))
            ->when($request->from, fn ($q, $v) => $q->whereDate('due_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('due_date', '<=', $v))
            ->when($request->sale_id, fn ($q, $v) => $q->whereHas('plan', fn ($q) => $q->where('sale_id', $v)))
            ->orderBy('due_date')->orderBy('seq_no')
            ->paginate($this->perPage($request));

        return $this->paginated($installments, InstallmentResource::class);
    }
}
