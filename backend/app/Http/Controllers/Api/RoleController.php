<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermissionsRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** A shop owner picks which permissions each of the shop's roles has. */
class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $shopId = auth()->user()->shop_id;

        return $this->success(collect(PermissionCatalog::ASSIGNABLE_ROLES)->map(fn ($role) => [
            'role' => $role,
            'label' => PermissionCatalog::ROLES[$role],
            'permissions' => DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('shop_id', $shopId)->where('role', $role)->pluck('permissions.key')->all(),
        ])->values(), 'OK', 200, [
            // every permission there is, so the page lists them from this one response
            'permissions' => PermissionResource::collection(Permission::orderBy('group')->orderBy('id')->get())->resolve(),
        ]);
    }

    public function update(RolePermissionsRequest $request, string $role): JsonResponse
    {
        abort_unless(in_array($role, PermissionCatalog::ASSIGNABLE_ROLES, true), 404, 'Resource not found');

        $shopId = $request->user()->shop_id;
        $ids = Permission::whereIn('key', $request->permissions)->pluck('id');

        DB::transaction(function () use ($shopId, $role, $ids) {
            DB::table('role_permissions')->where('shop_id', $shopId)->where('role', $role)->delete();
            DB::table('role_permissions')->insert($ids->map(fn ($id) => ['shop_id' => $shopId, 'role' => $role, 'permission_id' => $id])->all());
        });

        return $this->success(['role' => $role, 'permissions' => array_values($request->permissions)], PermissionCatalog::ROLES[$role].' permissions updated');
    }
}
