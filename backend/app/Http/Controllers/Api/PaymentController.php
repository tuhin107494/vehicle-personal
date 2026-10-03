<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PaymentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:payments.collect', only: ['store']),
        ];
    }

    public function store(StorePaymentRequest $request, Sale $sale, PaymentService $payments): JsonResponse
    {
        $created = $payments->collect($sale, $request->validated(), $request->user());

        return $this->success([
            'payments' => PaymentResource::collection(collect($created)),
            'sale' => SaleResource::make($sale->fresh()),
        ], 'Payment collected', 201);
    }
}
