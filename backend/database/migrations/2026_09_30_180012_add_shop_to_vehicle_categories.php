<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** shop_id null = platform category (super admin); otherwise a type that one shop added for itself. */
    public function up(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->foreignId('shop_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index(['shop_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'key']);
            $table->dropConstrainedForeignId('shop_id');
            $table->unique('key');
        });
    }
};
