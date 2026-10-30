<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a booking can be held with no deadline: it then stays until it is sold or cancelled
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('expires_on')->nullable(false)->change();
        });
    }
};
