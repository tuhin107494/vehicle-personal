<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->string('from_status');
            $table->string('to_status');
            $table->timestamp('changed_at');
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_logs');
    }
};
