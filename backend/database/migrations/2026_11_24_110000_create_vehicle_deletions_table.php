<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of each vehicle deleted with everything on it: who did it and when, the vehicle's numbers, and a summary of
 * the sales, purchases, payments and investor entries that went with it (the rows themselves are gone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_id'); // the vehicle no longer exists, so no foreign key
            $table->string('chassis_no');
            $table->string('engine_no')->nullable();
            $table->string('registration_no')->nullable();
            $table->string('vehicle_name')->nullable();
            $table->json('summary');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_deletions');
    }
};
