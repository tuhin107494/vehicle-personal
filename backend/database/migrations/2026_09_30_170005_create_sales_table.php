<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('purchase_id')->nullable()->constrained();
            $table->foreignId('party_id')->constrained();
            $table->date('sale_date');
            $table->decimal('sale_price', 14, 2);
            $table->decimal('down_payment', 14, 2)->default(0);
            $table->enum('payment_type', ['cash', 'due', 'installment']);
            $table->decimal('total_paid', 14, 2)->default(0);
            $table->decimal('due_amount', 14, 2)->default(0);
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('unpaid');
            $table->enum('status', ['active', 'cancelled'])->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('investor_id')->nullable()->constrained();
            $table->enum('registration_status', ['pending', 'bank_chalan_done', 'file_received', 'file_processing', 'registration_complete', 'document_provided'])->default('pending');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
