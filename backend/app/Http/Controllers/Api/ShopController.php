<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopRequest;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Platform level: only the super admin creates and manages shops. */
class ShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shops = Shop::with('owner')->withCount('users')
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->latest('id')->paginate($request->integer('per_page', 15));

        return $this->paginated($shops, ShopResource::class);
    }

    public function store(ShopRequest $request, ShopService $shops): JsonResponse
    {
        $shop = $shops->create(
            $request->safe()->only(['name', 'phone', 'address']) + ['is_active' => $request->boolean('is_active', true)],
            ['name' => $request->owner_name, 'email' => $request->owner_email, 'password' => $request->owner_password],
        );

        return $this->success(ShopResource::make($shop->load('owner')), 'Shop created', 201);
    }

    public function show(Shop $shop): JsonResponse
    {
        return $this->success(ShopResource::make($shop->load('owner')->loadCount('users')));
    }

    public function update(ShopRequest $request, Shop $shop): JsonResponse
    {
        $shop->update($request->safe()->only(['name', 'phone', 'address']) + ['is_active' => $request->boolean('is_active', $shop->is_active)]);

        if ($owner = $shop->owner) {
            $owner->fill(array_filter([
                'name' => $request->owner_name, 'email' => $request->owner_email, 'password' => $request->owner_password,
            ]));
            $owner->is_active = $shop->is_active;
            $owner->save();
        }

        if (! $shop->is_active) {
            // Sign everybody in this shop out.
            DB::table('personal_access_tokens')
                ->whereIn('tokenable_id', $shop->users()->pluck('id'))->where('tokenable_type', User::class)->delete();
        }

        return $this->success(ShopResource::make($shop->load('owner')), 'Shop updated');
    }
}
