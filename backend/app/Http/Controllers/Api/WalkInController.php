<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\WalkInRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\WalkInResource;
use App\Models\WalkIn;
use App\Services\WalkInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class WalkInController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:walkins.view', only: ['index', 'show']),
            new Middleware('permission:walkins.create', only: ['store']),
            new Middleware('permission:walkins.edit', only: ['update']),
            new Middleware('permission:walkins.delete', only: ['destroy']),
            // turning a walk-in into a customer edits the walk-in and adds a customer
            new Middleware('permission:walkins.edit', only: ['convert']),
            new Middleware('permission:parties.create', only: ['convert']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $walkIns = WalkIn::query()->with('creator:id,name')
            ->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")->orWhere('address', 'like', "%$s%")->orWhere('note', 'like', "%$s%")))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->paginated($walkIns, WalkInResource::class);
    }

    public function store(WalkInRequest $request): JsonResponse
    {
        $walkIn = WalkIn::create($request->validated() + ['created_by' => $request->user()->id]);

        return $this->success(WalkInResource::make($walkIn->load('creator:id,name')), 'Walk-in customer saved', 201);
    }

    public function show(WalkIn $walkIn): JsonResponse
    {
        return $this->success(WalkInResource::make($walkIn->load('creator:id,name')));
    }

    public function update(WalkInRequest $request, WalkIn $walkIn): JsonResponse
    {
        $walkIn->update($request->validated());

        return $this->success(WalkInResource::make($walkIn->load('creator:id,name')), 'Walk-in customer updated');
    }

    public function destroy(WalkIn $walkIn): JsonResponse
    {
        $walkIn->delete();

        return $this->success(null, 'Walk-in customer deleted');
    }

    public function convert(CustomerRequest $request, WalkIn $walkIn, WalkInService $walkIns): JsonResponse
    {
        $customer = $walkIns->convert($walkIn, $request->validated());

        return $this->success(CustomerResource::make($customer), 'Customer created', 201);
    }
}
