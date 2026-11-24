<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The new right to cancel a payment. It is added here, not only in the seeder, so a deploy brings it without a manual step.
     * A role that may delete payments keeps that power when the shop switches to cancelling, so it gets the new right too.
     */
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => 'transactions.cancel', 'name' => 'Cancel customer and supplier transactions', 'group' => 'accounts', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $cancel = DB::table('permissions')->where('key', 'transactions.cancel')->value('id');
        $delete = DB::table('permissions')->where('key', 'transactions.delete')->value('id');

        if (! $cancel || ! $delete) {
            return;
        }

        $roles = DB::table('role_permissions')->where('permission_id', $delete)->get(['shop_id', 'role']);

        foreach ($roles as $role) {
            DB::table('role_permissions')->insertOrIgnore(['shop_id' => $role->shop_id, 'role' => $role->role, 'permission_id' => $cancel]);
        }
    }

    public function down(): void
    {
        $cancel = DB::table('permissions')->where('key', 'transactions.cancel')->value('id');

        if ($cancel) {
            DB::table('role_permissions')->where('permission_id', $cancel)->delete();
            DB::table('permissions')->where('id', $cancel)->delete();
        }
    }
};
