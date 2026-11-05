<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** People who visited the shop but have not bought yet: their number, address and a note. */
    public function up(): void
    {
        Schema::create('walk_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('name')->nullable();
            $table->string('phone', 30);
            $table->text('address')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_ins');
    }
};
