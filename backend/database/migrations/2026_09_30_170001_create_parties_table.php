<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('name');
            $table->string('phone');
            $table->string('alt_phone')->nullable();
            $table->string('nid')->nullable();
            $table->text('address')->nullable();
            $table->enum('type', ['customer', 'supplier', 'both'])->default('customer');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['shop_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
