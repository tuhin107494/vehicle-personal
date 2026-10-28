<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\VehicleCategoryRequest;
use App\Models\ShopSetting;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform categories are managed by the super admin and used by every shop.
 * A shop can also add (and manage) types of its own from the vehicle type dropdown.
 */
class VehicleCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(VehicleCategory::orderBy('name')->get());
    }

    public function store(VehicleCategoryRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeManage($user);

        $key = Str::slug($request->name, '_');
        $taken = $user->isSuperAdmin() ? VehicleCategory::withoutGlobalScopes() : VehicleCategory::query();

        if ($taken->where('key', $key)->exists()) {
            throw new BusinessRuleException('A category with a similar name already exists.');
        }

        $price = $request->input('purchase_price');

        // the type and its starting price are saved together or not at all
        $category = DB::transaction(function () use ($request, $user, $key, $price) {
            $category = VehicleCategory::create($request->safe()->only(['name', 'default_brand', 'default_model', 'default_color', 'default_cc']) + [
                'key' => $key, 'shop_id' => $user->shop_id, 'created_by' => $user->id,
            ]);

            // A shop adding its own type can give it a starting purchase price, kept with the shop's per-type prices.
            if ($user->shop_id && $price) {
                ShopSetting::updateOrCreate(['key' => "purchase.price:{$category->key}"], ['value' => $price]);
            }

            return $category;
        });

        return $this->success($category->toArray() + ['purchase_price' => $user->shop_id && $price ? $price + 0 : null], 'Category created', 201);
    }

    public function update(VehicleCategoryRequest $request, VehicleCategory $category): JsonResponse
    {
        $this->authorizeOwn($request->user(), $category);

        $category->update($request->safe()->only(['name', 'default_brand', 'default_model', 'default_color', 'default_cc']));

        return $this->success($category, 'Category updated');
    }

    public function destroy(VehicleCategory $category): JsonResponse
    {
        $this->authorizeOwn(auth()->user(), $category);

        $vehicles = $category->shop_id ? Vehicle::query() : Vehicle::withoutGlobalScopes();

        if ($vehicles->where('vehicle_type', $category->key)->exists()) {
            throw new BusinessRuleException('Vehicles still use this category, so it cannot be deleted.');
        }

        $category->delete();

        return $this->success(null, 'Category deleted');
    }

    private function authorizeManage($user): void
    {
        abort_unless($user->isSuperAdmin() || $user->hasPermission('purchases.create') || $user->hasPermission('vehicles.edit'), 403, 'You do not have permission to do this.');
    }

    /** The super admin manages platform categories; a shop manages only its own. */
    private function authorizeOwn($user, VehicleCategory $category): void
    {
        $this->authorizeManage($user);

        abort_unless($category->shop_id === $user->shop_id, 403, 'This category is managed by the platform.');
    }
}
