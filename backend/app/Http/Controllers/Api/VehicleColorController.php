<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleColorRequest;
use App\Models\VehicleColor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class VehicleColorController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:purchases.create,vehicles.edit')];
    }

    /** Colours this shop added (the standard ones live in the app). */
    public function index(): JsonResponse
    {
        return $this->success(VehicleColor::orderBy('name')->pluck('name'));
    }

    public function store(VehicleColorRequest $request): JsonResponse
    {
        $color = VehicleColor::create(['name' => $request->name]);

        return $this->success($color->name, 'Colour added', 201);
    }
}
