<?php

namespace Database\Seeders;

use App\Models\VehicleCategory;
use Illuminate\Database\Seeder;

class VehicleCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['cng' => ['CNG', 'Bajaj', 'Green', 200], 'auto_rickshaw' => ['Auto Rickshaw', null, null, null], 'motorcycle' => ['Motorcycle', null, null, null], 'car' => ['Car', null, null, null]] as $key => [$name, $brand, $color, $cc]) {
            VehicleCategory::firstOrCreate(['key' => $key], ['name' => $name, 'default_brand' => $brand, 'default_color' => $color, 'default_cc' => $cc]);
        }
    }
}
