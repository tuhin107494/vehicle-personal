<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Http\Resources\VehicleResource;
use App\Models\Payment;
use App\Models\RegistrationLog;
use App\Models\Sale;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class VehicleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:vehicles.view', only: ['index', 'show', 'lookup', 'history', 'owned']),
            new Middleware('permission:vehicles.edit', only: ['update']),
            new Middleware('permission:vehicles.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $base = Vehicle::query()
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('chassis_no', 'like', "%$s%")->orWhere('engine_no', 'like', "%$s%")
                ->orWhere('registration_no', 'like', "%$s%")->orWhere('brand', 'like', "%$s%")->orWhere('model', 'like', "%$s%")));

        // Tab badges: counts per type for the current status/search, ignoring the selected type.
        $counts = (clone $base)->selectRaw('vehicle_type, COUNT(*) as total')->groupBy('vehicle_type')->pluck('total', 'vehicle_type');

        $vehicles = $base->with('currentPurchase')
            ->withSum(['sales as sale_due' => fn ($q) => $q->where('status', 'active')], 'due_amount')
            ->when($request->vehicle_type, fn ($q, $t) => $q->where('vehicle_type', $t))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        // where each sold vehicle's registration stands (the sale's step)
        $steps = Sale::whereIn('id', $vehicles->getCollection()->pluck('current_sale_id')->filter())->pluck('registration_status', 'id');
        $vehicles->getCollection()->each(fn (Vehicle $v) => $v->setAttribute('registration_status', $steps[$v->current_sale_id] ?? null));

        return $this->success(VehicleResource::collection($vehicles->getCollection()), 'OK', 200, [
            'current_page' => $vehicles->currentPage(),
            'last_page' => $vehicles->lastPage(),
            'per_page' => $vehicles->perPage(),
            'total' => $vehicles->total(),
            'type_counts' => $counts,
        ]);
    }

    public function show(Vehicle $vehicle): JsonResponse
    {
        return $this->success(VehicleResource::make($vehicle->load(['currentPurchase.supplier', 'currentPurchase.customer', 'currentSale.customer'])));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $vehicle->update($request->validated());

        return $this->success(VehicleResource::make($vehicle), 'Vehicle updated');
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        if ($vehicle->purchases()->exists()) {
            throw new BusinessRuleException('This vehicle has purchase history and cannot be deleted.');
        }

        $vehicle->delete();

        return $this->success(null, 'Vehicle deleted');
    }

    /** Find a vehicle by chassis number or by registration number (used to repurchase a vehicle we already know). */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'chassis_no' => ['required_without:registration_no', 'nullable', 'string'],
            'registration_no' => ['required_without:chassis_no', 'nullable', 'string'],
        ], ['chassis_no.required_without' => 'Enter a chassis or registration number to look up.']);

        $vehicle = Vehicle::with('currentSale.customer') // who has it now, so the buy form can select that customer
            ->when($request->filled('chassis_no'), fn ($q) => $q->where('chassis_no', trim($request->chassis_no)))
            ->when(! $request->filled('chassis_no'), fn ($q) => $q->where('registration_no', trim($request->registration_no)))
            ->first();

        return $this->success($vehicle ? VehicleResource::make($vehicle) : null, $vehicle ? 'Vehicle found' : 'No vehicle found');
    }

    /**
     * Vehicles that are currently with a customer we sold them to, found by the customer's name or phone,
     * (or NID), or by the vehicle's chassis, engine or registration number. Used to buy a vehicle back second hand.
     */
    public function owned(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('search'));

        if (mb_strlen($term) < 2) {
            return $this->success([]);
        }

        $sales = Sale::with(['vehicle', 'customer'])
            ->where('status', 'active')
            ->whereHas('vehicle', fn ($q) => $q->where('status', 'sold')->whereColumn('vehicles.current_sale_id', 'sales.id'))
            ->where(fn ($q) => $q
                ->whereHas('customer', fn ($q) => $q->where('name', 'like', "%$term%")->orWhere('phone', 'like', "%$term%")->orWhere('alt_phone', 'like', "%$term%")->orWhere('nid', 'like', "%$term%"))
                ->orWhereHas('vehicle', fn ($q) => $q->where('chassis_no', 'like', "%$term%")->orWhere('engine_no', 'like', "%$term%")->orWhere('registration_no', 'like', "%$term%")))
            ->latest('sale_date')->latest('id')->limit(20)->get();

        return $this->success(SaleResource::collection($sales));
    }

    public function history(Vehicle $vehicle): JsonResponse
    {
        $purchases = $vehicle->purchases()->with(['supplier', 'customer'])->get();
        $sales = $vehicle->sales()->with(['customer', 'payments'])->orderBy('sale_date')->orderBy('id')->get();
        $saleIds = $sales->pluck('id');
        $events = collect();

        foreach ($purchases as $p) {
            $events->push([
                'type' => 'purchase', 'date' => $p->purchase_date->toDateString(), 'sort' => $p->purchase_date->copy()->addSeconds($p->id),
                'title' => ($p->source === 'customer' ? 'Returned by ' : 'Purchased from ').($p->source === 'customer' ? $p->customer : $p->supplier)->name, 'amount' => $p->price, 'ref_id' => $p->id, 'data' => $p,
                'how' => collect([
                    (float) $p->paid_amount > 0 ? ucfirst($p->payment_method ?: 'cash').' '.number_format((float) $p->paid_amount) : null,
                    (float) $p->due_adjustment > 0 ? 'Adjustment '.number_format((float) $p->due_adjustment) : null,
                ])->filter()->implode(' · ') ?: null,
            ]);
        }

        foreach ($sales as $s) {
            $events->push([
                'type' => 'sale', 'date' => $s->sale_date->toDateString(), 'sort' => $s->sale_date->copy()->addSeconds($s->id),
                'title' => 'Sold to '.$s->customer->name.' ('.$s->payment_type.')', 'amount' => $s->sale_price, 'ref_id' => $s->id, 'data' => $s, 'how' => null,
            ]);

            if ($s->status === 'cancelled') {
                $events->push([
                    'type' => 'sale_cancelled', 'date' => $s->cancelled_at->toDateString(), 'sort' => $s->cancelled_at,
                    'title' => 'Sale #'.$s->id.' cancelled: '.$s->cancel_reason, 'amount' => null, 'ref_id' => $s->id, 'data' => null,
                ]);
            }
        }

        RegistrationLog::with('user')->whereIn('sale_id', $saleIds)->get()->each(fn ($l) => $events->push([
            'type' => 'registration', 'date' => $l->changed_at->toDateString(), 'sort' => $l->changed_at,
            'title' => 'Registration: '.str_replace('_', ' ', $l->from_status).' → '.str_replace('_', ' ', $l->to_status),
            'amount' => null, 'ref_id' => $l->sale_id, 'data' => ['note' => $l->note, 'by' => $l->user->name],
        ]));

        Payment::whereIn('sale_id', $saleIds)->get()->each(fn ($pay) => $events->push([
            'type' => 'payment', 'date' => $pay->paid_at->toDateString(), 'sort' => $pay->paid_at,
            'title' => ($pay->amount < 0 ? 'Refund' : 'Payment').' on sale #'.$pay->sale_id,
            'amount' => $pay->amount, 'ref_id' => $pay->sale_id, 'data' => null,
            'how' => collect([ucfirst($pay->method), $pay->reference ? 'Ref '.$pay->reference : null])->filter()->implode(' · '),
        ]));

        return $this->success([
            'vehicle' => VehicleResource::make($vehicle),
            'sales' => SaleResource::collection($sales),
            'purchases' => PurchaseResource::collection($purchases),
            'timeline' => $events->sortBy('sort')->values()->map(fn ($e) => collect($e)->except('sort')->all()),
        ]);
    }
}
