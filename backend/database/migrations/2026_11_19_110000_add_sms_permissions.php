<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The SMS rights, added here so a deploy brings them. A role that manages the shop's settings gets them too. */
    public function up(): void
    {
        $now = now();
        DB::table('permissions')->insertOrIgnore([
            ['key' => 'sms.view', 'name' => 'View the SMS log', 'group' => 'sms', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sms.manage', 'name' => 'Turn SMS reminders on or off and retry failed SMS', 'group' => 'sms', 'created_at' => $now, 'updated_at' => $now],
        ]);

        $settings = DB::table('permissions')->where('key', 'settings.manage')->value('id');
        $new = DB::table('permissions')->whereIn('key', ['sms.view', 'sms.manage'])->pluck('id');

        if (! $settings) {
            return;
        }

        foreach (DB::table('role_permissions')->where('permission_id', $settings)->get(['shop_id', 'role']) as $role) {
            foreach ($new as $id) {
                DB::table('role_permissions')->insertOrIgnore(['shop_id' => $role->shop_id, 'role' => $role->role, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', ['sms.view', 'sms.manage'])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
