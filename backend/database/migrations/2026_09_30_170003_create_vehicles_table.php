<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('chassis_no');
            $table->string('engine_no');
            $table->string('registration_no')->nullable();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('color')->nullable();
            $table->unsignedInteger('cc')->nullable();
            $table->string('fuel_type')->nullable();
            $table->enum('status', ['in_stock', 'sold', 'booked'])->default('in_stock')->index();
            $table->unsignedBigInteger('current_purchase_id')->nullable();
            $table->unsignedBigInteger('current_sale_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['shop_id', 'chassis_no']);
            $table->unique(['shop_id', 'engine_no']);
            $table->unique(['shop_id', 'registration_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
