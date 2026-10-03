<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\ShopService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(ShopService $shops): void
    {
        $this->call(PermissionSeeder::class);
        $this->call(VehicleCategorySeeder::class);

        User::create(['name' => 'Super Admin', 'email' => 'superadmin@example.com', 'password' => 'password', 'role' => 'super_admin']);

        $shop = $shops->create(
            ['name' => 'Demo Motors', 'phone' => '01700000000'],
            ['name' => 'Shop Owner', 'email' => 'owner@example.com', 'password' => 'password'],
        );

        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'admin', 'shop_id' => $shop->id]);
        User::create(['name' => 'Moderator', 'email' => 'moderator@example.com', 'password' => 'password', 'role' => 'moderator', 'shop_id' => $shop->id]);
    }
}
