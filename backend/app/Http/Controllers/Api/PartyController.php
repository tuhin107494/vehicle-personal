<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PartyRequest;
use App\Http\Resources\PartyResource;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PartyController extends Controller implements HasMiddleware
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
        $parties = Party::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('father_name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('alt_phone', 'like', "%$s%")->orWhere('nid', 'like', "%$s%")))
            ->when($request->type, fn ($q, $t) => $q->whereIn('type', [$t, 'both']))
            ->latest('id')
            ->paginate($request->integer('per_page', 15));

        // what they owe us (customer) and what we owe them (supplier), for the "collect / pay" shortcuts
        $ids = $parties->getCollection()->pluck('id');
        $receivable = Sale::where('status', 'active')->whereIn('party_id', $ids)->selectRaw('party_id, SUM(due_amount) as total')->groupBy('party_id')->pluck('total', 'party_id');
        $payable = Purchase::whereIn('party_id', $ids)->selectRaw('party_id, SUM(CASE WHEN price - paid_amount - due_adjustment > 0 THEN price - paid_amount - due_adjustment ELSE 0 END) as total')->groupBy('party_id')->pluck('total', 'party_id');
        $parties->getCollection()->each(function (Party $p) use ($receivable, $payable) {
            $p->setAttribute('receivable_due', round((float) ($receivable[$p->id] ?? 0), 2));
            $p->setAttribute('payable_due', round((float) ($payable[$p->id] ?? 0), 2));
        });

        return $this->paginated($parties, PartyResource::class);
    }

    public function store(PartyRequest $request): JsonResponse
    {
        return $this->success(PartyResource::make(Party::create($this->forType($request->validated()))), 'Party created', 201);
    }

    public function show(Party $party): JsonResponse
    {
        return $this->success(PartyResource::make($party));
    }

    public function update(PartyRequest $request, Party $party): JsonResponse
    {
        $party->update($this->forType($request->validated()));

        return $this->success(PartyResource::make($party), 'Party updated');
    }

    public function destroy(Party $party): JsonResponse
    {
        if ($party->purchases()->exists() || $party->sales()->exists()) {
            throw new BusinessRuleException('This party has purchases or sales and cannot be deleted.');
        }

        $party->delete();

        return $this->success(null, 'Party deleted');
    }

    public function history(Party $party): JsonResponse
    {
        return $this->success([
            'party' => PartyResource::make($party),
            'purchases' => PurchaseResource::collection($party->purchases()->with('vehicle')->latest('purchase_date')->get()),
            'sales' => SaleResource::collection($party->sales()->with('vehicle')->latest('sale_date')->get()),
        ]);
    }

    /** Father's name belongs to customers only. */
    private function forType(array $data): array
    {
        if (($data['type'] ?? null) === 'supplier') {
            $data['father_name'] = null;
        }

        // bank details are for the people we pay
        if (($data['type'] ?? null) === 'customer') {
            foreach (['bank_name', 'bank_branch', 'bank_account_name', 'bank_account_no', 'bank_info'] as $field) {
                $data[$field] = null;
            }
        }

        return $data;
    }
}
