<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SupplierResource;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SupplierController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:parties.view', only: ['index', 'show', 'history']),
            new Middleware('permission:parties.create', only: ['store']),
            new Middleware('permission:parties.edit', only: ['update']),
            new Middleware('permission:parties.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $suppliers = Supplier::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('alt_phone', 'like', "%$s%")->orWhere('nid', 'like', "%$s%")))
            ->latest('id')
            ->paginate($this->perPage($request));

        // what we still owe each supplier, for the "Pay" shortcut
        $owed = Purchase::whereIn('supplier_id', $suppliers->getCollection()->pluck('id'))
            ->selectRaw('supplier_id, SUM(CASE WHEN price - paid_amount - due_adjustment > 0 THEN price - paid_amount - due_adjustment ELSE 0 END) as total')
            ->groupBy('supplier_id')->pluck('total', 'supplier_id');
        $suppliers->getCollection()->each(fn (Supplier $s) => $s->setAttribute('payable_due', (float) ($owed[$s->id] ?? 0)));

        return $this->paginated($suppliers, SupplierResource::class);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return $this->success(SupplierResource::make(Supplier::create($request->validated())), 'Supplier created', 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success(SupplierResource::make($supplier));
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($request->validated());

        return $this->success(SupplierResource::make($supplier), 'Supplier updated');
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchases()->exists() || $supplier->advances()->exists()) {
            throw new BusinessRuleException('This supplier has purchases or advances and cannot be deleted.');
        }

        $supplier->delete();

        return $this->success(null, 'Supplier deleted');
    }

    public function history(Supplier $supplier): JsonResponse
    {
        return $this->success([
            'supplier' => SupplierResource::make($supplier),
            'purchases' => PurchaseResource::collection($supplier->purchases()->with('vehicle')->latest('purchase_date')->get()),
            'sales' => [],
        ]);
    }
}
