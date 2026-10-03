<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PermissionRequest;
use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        return $this->success(PermissionResource::collection(Permission::orderBy('group')->orderBy('id')->get()));
    }

    public function store(PermissionRequest $request): JsonResponse
    {
        $permission = Permission::create($request->validated() + ['created_by' => $request->user()->id]);

        return $this->success(PermissionResource::make($permission), 'Permission created', 201);
    }

    public function update(PermissionRequest $request, Permission $permission): JsonResponse
    {
        $permission->update($request->validated());

        return $this->success(PermissionResource::make($permission), 'Permission updated');
    }

    public function destroy(Permission $permission): JsonResponse
    {
        $permission->delete();

        return $this->success(null, 'Permission deleted');
    }
}
