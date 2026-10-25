<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SettingsRequest;
use App\Http\Requests\ShopProfileRequest;
use App\Support\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        // Every shop user reads the settings (the forms need them); only managers change them.
        return [new Middleware('permission:settings.manage', only: ['update', 'updateShop'])];
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

    /** The signed-in user's own shop: name. */
    public function shop(Request $request): JsonResponse
    {
        return $this->success($request->user()->shop->only(['id', 'name']));
    }

    /** The shop's owner (anyone allowed to manage settings) renames the shop. */
    public function updateShop(ShopProfileRequest $request): JsonResponse
    {
        $shop = $request->user()->shop;
        $shop->update($request->validated());

        return $this->success($shop->only(['id', 'name']), 'Shop updated');
    }
}
