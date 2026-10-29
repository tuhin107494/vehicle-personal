<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // an advance can be taken before the price is agreed: the price is set when the booking becomes a sale
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('agreed_price', 14, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('agreed_price', 14, 2)->nullable(false)->change();
        });
    }
};
