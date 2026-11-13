<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\PlanResource;
use App\Models\Billing\Plan;
use Illuminate\Http\JsonResponse;

/** The plans on offer, for the landing page and the shop's billing page. Public: it says nothing about any shop. */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(PlanResource::collection(Plan::where('is_active', true)->with('features')->orderBy('sort_order')->orderBy('price_paisa')->get()));
    }
}
