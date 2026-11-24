<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A cancelled payment stays on the books with who cancelled it and why; every total skips it. */
    public function up(): void
    {
        foreach (['payments', 'purchase_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('cancelled_at')->nullable()->index();
                $table->foreignId('cancelled_by')->nullable()->constrained('users');
                $table->string('cancel_reason')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'purchase_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['cancelled_by']);
                $table->dropIndex($name.'_cancelled_at_index');
                $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancel_reason']);
            });
        }
    }
};
