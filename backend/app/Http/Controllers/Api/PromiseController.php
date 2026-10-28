<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePromiseRequest;
use App\Http\Resources\DuePromiseResource;
use App\Models\DuePromise;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class PromiseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:promises.view', only: ['index']),
            new Middleware('permission:promises.manage', only: ['store']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $promises = DuePromise::query()
            ->with(['sale.vehicle', 'sale.customer'])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('promised_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('promised_date', '<=', $v))
            ->orderBy('promised_date')
            ->paginate($this->perPage($request));

        return $this->paginated($promises, DuePromiseResource::class);
    }

    public function store(StorePromiseRequest $request, Sale $sale): JsonResponse
    {
        if ($sale->status !== 'active' || $sale->due_amount <= 0) {
            throw new BusinessRuleException('A promise can only be added to an active sale that still has an amount due.');
        }

        // one unit: the new promise and the settling of the earlier ones land together or not at all
        $promise = DB::transaction(function () use ($request, $sale) {
            $promise = DuePromise::create([
                'sale_id' => $sale->id,
                'promised_date' => $request->promised_date,
                'promised_amount' => $request->input('promised_amount', $sale->due_amount),
                'note' => $request->note,
                'created_by' => $request->user()->id,
            ]);

            // A renewal settles the earlier open promises: a missed one stays on record as broken, one still ahead is cancelled.
            $earlier = $sale->promises()->where('status', 'open')->where('id', '!=', $promise->id);
            (clone $earlier)->whereDate('promised_date', '<', today())->update(['status' => 'broken']);
            $earlier->update(['status' => 'cancelled']);

            return $promise;
        });

        return $this->success(DuePromiseResource::make($promise), 'Promise recorded', 201);
    }
}
