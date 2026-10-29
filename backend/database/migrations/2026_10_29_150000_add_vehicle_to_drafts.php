<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A sale draft that has a vehicle chosen holds that vehicle (it is "booked") until the draft is deleted or becomes the sale. */
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->after('type')->constrained('vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vehicle_id');
        });
    }
};
