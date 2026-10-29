<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A customer reserves a vehicle with an advance: the vehicle is held ("booked") until the booking is completed, cancelled or expires.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->decimal('agreed_price', 14, 2);
            $table->decimal('advance_amount', 14, 2);
            $table->enum('advance_method', ['cash', 'bank', 'mobile'])->default('cash');
            $table->date('booked_on');
            $table->date('expires_on');
            $table->enum('status', ['active', 'completed', 'cancelled', 'expired'])->default('active');
            $table->foreignId('sale_id')->nullable()->constrained();
            $table->decimal('refund_amount', 14, 2)->default(0); // given back when it was cancelled or expired
            $table->decimal('kept_amount', 14, 2)->default(0); // what the shop kept of the advance
            $table->date('closed_on')->nullable();
            $table->text('note')->nullable();
            $table->text('close_note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['status', 'expires_on']);
            $table->index(['vehicle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
