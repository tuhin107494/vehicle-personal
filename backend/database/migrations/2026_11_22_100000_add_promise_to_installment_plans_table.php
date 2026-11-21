<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An installment sale can have a promised amount: part of the price the customer promises to pay by a date, outside the
 * installments and without interest (e.g. 600,000 with 200,000 down and 50,000 promised: 350,000 + interest in installments).
 * It is kept as the plan's installment number 0, due on the promise date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->decimal('promise_amount', 14, 2)->default(0)->after('principal');
            $table->date('promise_date')->nullable()->after('promise_amount');
        });
    }

    public function down(): void
    {
        Schema::table('installment_plans', function (Blueprint $table) {
            $table->dropColumn(['promise_amount', 'promise_date']);
        });
    }
};
