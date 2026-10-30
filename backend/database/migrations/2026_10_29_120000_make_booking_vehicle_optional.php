<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a customer may pay an advance before a vehicle is chosen: the vehicle is picked when the booking becomes a sale
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable(false)->change();
        });
    }
};
