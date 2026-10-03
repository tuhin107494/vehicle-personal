<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // a purchase is from a supplier or, for a second-hand vehicle bought back, from a customer
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('source', ['supplier', 'customer'])->default('supplier')->index();
            $table->string('memo_no', 100)->nullable();
            $table->date('purchase_date');
            $table->decimal('price', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('due_adjustment', 14, 2)->default(0);
            $table->unsignedBigInteger('adjusted_sale_id')->nullable()->index(); // its foreign key is added once sales exists
            $table->decimal('cost_extra', 14, 2)->default(0);
            $table->string('payment_status')->default('unpaid');
            $table->enum('payment_method', ['cash', 'bank', 'mobile'])->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['shop_id', 'supplier_id', 'memo_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
