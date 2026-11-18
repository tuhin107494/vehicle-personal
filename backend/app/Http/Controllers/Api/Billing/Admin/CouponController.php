<?php

namespace App\Http\Controllers\Api\Billing\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\CouponRequest;
use App\Http\Resources\Billing\CouponResource;
use App\Models\Billing\Coupon;
use App\Models\Gateway\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Discount codes, super admin only. Invoices keep a reference to the coupon they used, so a coupon is switched off, never deleted. */
class CouponController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::when($request->search, fn ($q, $s) => $q->where('code', 'like', "%$s%"))->latest('id')->paginate($this->perPage($request));

        return $this->paginated($coupons, CouponResource::class);
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create($this->fields($request));
        AdminAuditLog::record($request->user(), 'coupon.created', $coupon, [['field' => 'code', 'to' => $coupon->code]], $request->ip());

        return $this->success(CouponResource::make($coupon), 'Coupon created', 201);
    }

    public function update(CouponRequest $request, Coupon $coupon): JsonResponse
    {
        $before = $coupon->only(['code', 'type', 'value', 'max_uses', 'expires_at', 'is_active']);
        $coupon->update($this->fields($request));
        $changes = collect($coupon->only(array_keys($before)))->filter(fn ($v, $k) => (string) $before[$k] !== (string) $v)->map(fn ($v, $k) => ['field' => $k, 'from' => $before[$k], 'to' => $v])->values()->all();
        AdminAuditLog::record($request->user(), 'coupon.updated', $coupon, $changes, $request->ip());

        return $this->success(CouponResource::make($coupon), 'Coupon updated');
    }

    /** @return array<string, mixed> */
    private function fields(CouponRequest $request): array
    {
        return $request->safe()->except('is_active') + ['code' => strtoupper($request->validated('code')), 'is_active' => $request->boolean('is_active', true)];
    }
}
