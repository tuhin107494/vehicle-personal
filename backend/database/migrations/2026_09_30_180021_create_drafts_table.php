<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A purchase or sale saved half-finished. It holds the form as typed and has no effect on stock or payments;
     * finishing it means submitting the normal form, after which the draft is deleted.
     */
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['purchase', 'sale']);
            $table->string('label')->nullable();
            $table->json('data');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['shop_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
