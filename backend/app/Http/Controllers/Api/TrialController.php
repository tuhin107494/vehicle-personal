<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExtendTrialRequest;
use App\Http\Resources\ShopResource;
use App\Models\Shop;
use App\Services\TrialService;
use Illuminate\Http\JsonResponse;

/** Platform level: only the super admin extends a shop's trial. */
class TrialController extends Controller
{
    public function extend(ExtendTrialRequest $request, Shop $shop, TrialService $trials): JsonResponse
    {
        $shop = $trials->extend($shop, $request->integer('days'));

        return $this->success(ShopResource::make($shop->load('owner')), 'Trial extended');
    }
}
