<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every edit of a sale: who made it and what changed (field, from, to). */
    public function up(): void
    {
        Schema::create('sale_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('sale_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_edits');
    }
};
