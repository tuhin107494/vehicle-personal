<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('value')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'key']);
        });

        // New permission: give it to every existing shop's Admin role (owners always have everything).
        $id = DB::table('permissions')->insertGetId([
            'key' => 'settings.manage', 'name' => 'Manage shop settings', 'group' => 'settings',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (DB::table('shops')->pluck('id') as $shopId) {
            DB::table('role_permissions')->insertOrIgnore(['shop_id' => $shopId, 'role' => 'admin', 'permission_id' => $id]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('key', 'settings.manage')->delete();
        Schema::dropIfExists('shop_settings');
    }
};
