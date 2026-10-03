<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdvanceRegistrationRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RegistrationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:registration.update', only: ['update']),
        ];
    }

    public function update(AdvanceRegistrationRequest $request, Sale $sale, RegistrationService $registration): JsonResponse
    {
        $sale = $registration->advance($sale, $request->status, $request->note, $request->user());

        return $this->success(SaleResource::make($sale->load('registrationLogs.user')), 'Registration updated');
    }
}
