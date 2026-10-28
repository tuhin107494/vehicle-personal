<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShopUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** A shop owner manages the admins and moderators of their own shop. */
class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::where('shop_id', auth()->user()->shop_id)
            ->orderByRaw("role = 'shop_owner' desc")->orderBy('name')->get();

        return $this->success(UserResource::collection($users));
    }

    public function store(ShopUserRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password', 'role']) + [
            'shop_id' => $request->user()->shop_id,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return $this->success(UserResource::make($user), 'User created', 201);
    }

    public function update(ShopUserRequest $request, User $user): JsonResponse
    {
        $this->authorizeTarget($user);

        $data = $request->safe()->only(['name', 'email', 'role']);

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        DB::transaction(function () use ($user, $data, $request) {
            $user->fill($data + ['is_active' => $request->boolean('is_active', $user->is_active)])->save();

            if (! $user->is_active) {
                DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->where('tokenable_type', User::class)->delete();
            }
        });

        return $this->success(UserResource::make($user), 'User updated');
    }

    private function authorizeTarget(User $user): void
    {
        abort_unless($user->shop_id === auth()->user()->shop_id, 404, 'Resource not found');

        if (! in_array($user->role, PermissionCatalog::ASSIGNABLE_ROLES, true)) {
            throw new BusinessRuleException('Only admins and moderators can be edited here.');
        }
    }
}
