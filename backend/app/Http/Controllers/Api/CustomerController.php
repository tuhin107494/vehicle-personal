<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class CustomerController extends Controller implements HasMiddleware
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
        $customers = Customer::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('father_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('alt_phone', 'like', "%$s%")->orWhere('nid', 'like', "%$s%")))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        // what each customer still owes us, for the "Due collection" shortcut
        $owed = Sale::where('status', 'active')->whereIn('customer_id', $customers->getCollection()->pluck('id'))
            ->selectRaw('customer_id, SUM(due_amount) as total')->groupBy('customer_id')->pluck('total', 'customer_id');
        $customers->getCollection()->each(fn (Customer $c) => $c->setAttribute('receivable_due', (float) ($owed[$c->id] ?? 0)));

        return $this->paginated($customers, CustomerResource::class);
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return $this->success(CustomerResource::make(Customer::create($request->validated())), 'Customer created', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->success(CustomerResource::make($customer));
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer->update($request->validated());

        return $this->success(CustomerResource::make($customer), 'Customer updated');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->purchases()->exists() || $customer->sales()->exists()) {
            throw new BusinessRuleException('This customer has sales or purchases and cannot be deleted.');
        }

        $customer->delete();

        return $this->success(null, 'Customer deleted');
    }

    public function history(Customer $customer): JsonResponse
    {
        return $this->success([
            'customer' => CustomerResource::make($customer),
            'purchases' => PurchaseResource::collection($customer->purchases()->with('vehicle')->latest('purchase_date')->get()),
            'sales' => SaleResource::collection($customer->sales()->with('vehicle')->latest('sale_date')->get()),
        ]);
    }
}
