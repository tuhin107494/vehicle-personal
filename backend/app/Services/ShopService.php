<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Shop;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Support\Facades\DB;

class ShopService
{
    /** Create a shop with its owner and the default Admin/Moderator permissions. */
    public function create(array $shop, array $owner): Shop
    {
        return DB::transaction(function () use ($shop, $owner) {
            $shop = Shop::create($shop);

            // listed first so they win: whatever $owner holds, this user owns this shop
            User::create(['shop_id' => $shop->id, 'role' => 'shop_owner'] + $owner);
            $this->seedDefaultPermissions($shop);

            return $shop;
        });
    }

    public function seedDefaultPermissions(Shop $shop): void
    {
        $ids = Permission::pluck('id', 'key');

        foreach (['admin' => PermissionCatalog::adminDefaults(), 'moderator' => PermissionCatalog::moderatorDefaults()] as $role => $keys) {
            DB::table('role_permissions')->insertOrIgnore(
                collect($keys)->filter(fn ($k) => isset($ids[$k]))->map(fn ($k) => [
                    'shop_id' => $shop->id, 'role' => $role, 'permission_id' => $ids[$k],
                ])->all()
            );
        }
    }
}
