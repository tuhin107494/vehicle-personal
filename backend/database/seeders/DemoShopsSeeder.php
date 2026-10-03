<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use Illuminate\Database\Seeder;

/** A few more shops, so the super admin has something to look at. Safe to re-run. */
class DemoShopsSeeder extends Seeder
{
    public function run(ShopService $shops): void
    {
        foreach ([
            ['Dhaka Auto Mart', '01700000101', 'Rafiq Hasan', 'rafiq@example.com'],
            ['City Vehicles', '01700000102', 'Mina Akter', 'mina@example.com'],
        ] as [$name, $phone, $owner, $email]) {
            if (Shop::where('phone', $phone)->exists() || User::where('email', $email)->exists()) {
                continue;
            }

            $shops->create(['name' => $name, 'phone' => $phone], ['name' => $owner, 'email' => $email, 'password' => 'password']);
        }
    }
}
