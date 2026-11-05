<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A user's permissions are their role's, plus the ones added for them, minus the ones taken away. */
class UserPermissionService
{
    /**
     * Makes the user's permissions exactly the given keys, by saving only how they differ from the role's.
     *
     * @param  array<int, string>  $keys
     */
    public function sync(User $user, array $keys): void
    {
        DB::transaction(function () use ($user, $keys) {
            $defaults = $this->roleKeys($user->shop_id, $user->role);
            $ids = Permission::pluck('id', 'key');
            $wanted = array_values(array_unique($keys));

            $rows = [];
            foreach (array_diff($wanted, $defaults) as $key) {
                $rows[] = ['shop_id' => $user->shop_id, 'user_id' => $user->id, 'permission_id' => $ids[$key], 'granted' => true];
            }
            foreach (array_diff($defaults, $wanted) as $key) {
                $rows[] = ['shop_id' => $user->shop_id, 'user_id' => $user->id, 'permission_id' => $ids[$key], 'granted' => false];
            }

            $this->clear($user);
            DB::table('user_permissions')->insert($rows);
        });
    }

    /** Back to exactly what the role gives. */
    public function clear(User $user): void
    {
        DB::table('user_permissions')->where('shop_id', $user->shop_id)->where('user_id', $user->id)->delete();
    }

    /** @return array<int, string> */
    private function roleKeys(?int $shopId, string $role): array
    {
        return DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.shop_id', $shopId)->where('role_permissions.role', $role)
            ->pluck('permissions.key')->all();
    }
}
