<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every edit of a purchase: who made it and what changed (field, from, to). */
    public function up(): void
    {
        Schema::create('purchase_edits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('purchase_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->json('changes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_edits');
    }
};
