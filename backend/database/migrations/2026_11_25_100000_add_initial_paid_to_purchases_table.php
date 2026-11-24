<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was paid when the purchase was made (not a payment row), so a purchase's paid_amount can always be summed again:
 * this plus its payments. Filled from what is there now: paid_amount less the payments not cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('initial_paid', 14, 2)->default(0)->after('paid_amount');
        });

        $payments = DB::table('purchase_payments')->whereNull('cancelled_at')->groupBy('purchase_id')->selectRaw('purchase_id, SUM(amount) as total')->pluck('total', 'purchase_id');

        DB::table('purchases')->orderBy('id')->select(['id', 'paid_amount'])->chunkById(500, function ($purchases) use ($payments) {
            foreach ($purchases as $p) {
                $initial = round((float) $p->paid_amount - (float) ($payments[$p->id] ?? 0), 2);
                DB::table('purchases')->where('id', $p->id)->update(['initial_paid' => max($initial, 0)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('initial_paid');
        });
    }
};
