<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PurchaseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:purchases.view', only: ['index', 'show']),
            new Middleware('permission:purchases.create', only: ['store']),
            new Middleware('permission:purchases.edit', only: ['update']),
            new Middleware('permission:purchases.delete', only: ['destroy']),
        ];
    }

    public function __construct(private PurchaseService $purchases) {}

    public function index(Request $request): JsonResponse
    {
        $purchases = Purchase::query()
            ->with(['vehicle', 'supplier', 'customer'])
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->vehicle_id, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('memo_no', 'like', "%$s%")
                ->orWhereHas('vehicle', fn ($q) => $q->where('chassis_no', 'like', "%$s%")->orWhere('model', 'like', "%$s%"))
                ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', "%$s%"))
                ->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%$s%"))))
            ->latest('purchase_date')->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($purchases, PurchaseResource::class);
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->create($request->validated(), $request->user());

        return $this->success(PurchaseResource::make($purchase->load(['vehicle', 'supplier', 'customer'])), 'Purchase recorded', 201);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return $this->success(PurchaseResource::make($purchase->load(['vehicle', 'supplier', 'customer', 'creator'])));
    }

    public function update(UpdatePurchaseRequest $request, Purchase $purchase): JsonResponse
    {
        $purchase = $this->purchases->update($purchase, $request->validated());

        return $this->success(PurchaseResource::make($purchase->load(['vehicle', 'supplier', 'customer'])), 'Purchase updated');
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        $this->purchases->delete($purchase);

        return $this->success(null, 'Purchase deleted');
    }
}
