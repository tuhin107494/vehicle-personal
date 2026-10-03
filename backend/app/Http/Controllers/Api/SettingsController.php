<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // Every shop user reads the settings (the forms need them); only managers change them.
        return [new Middleware('permission:settings.manage', only: ['update'])];
    }

    public function show(): JsonResponse
    {
        return $this->success(ShopSettings::all());
    }

    public function update(SettingsRequest $request): JsonResponse
    {
        ShopSettings::save($request->validated());

        return $this->success(ShopSettings::all(), 'Settings saved');
    }
}
