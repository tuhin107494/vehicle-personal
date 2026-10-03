<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

/** Built-in permission catalog. Safe to re-run; it never touches what a shop owner has assigned to roles. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::all() as [$key, $name, $group]) {
            Permission::updateOrCreate(['key' => $key], ['name' => $name, 'group' => $group]);
        }
    }
}
