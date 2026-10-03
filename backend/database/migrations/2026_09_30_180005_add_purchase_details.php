<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('registration_date')->nullable();
            $table->date('tax_token_expiry')->nullable();
            $table->date('fitness_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->unsignedTinyInteger('previous_owners')->nullable();
            $table->boolean('papers_received')->default(false);
            $table->unsignedInteger('mileage')->nullable();
            $table->text('condition_notes')->nullable();
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->enum('payment_method', ['cash', 'bank', 'mobile'])->nullable();
            $table->string('payment_reference')->nullable();
        });

        Schema::create('vehicle_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_photos');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['registration_date', 'tax_token_expiry', 'fitness_expiry', 'insurance_expiry', 'previous_owners', 'papers_received', 'mileage', 'condition_notes']);
        });
    }
};
