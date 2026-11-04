<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** One collection can be spread over several installments (one payment row each): the rows of one collection share a batch, shown as a single invoice. */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('batch_no', 40)->nullable()->after('sale_charge_id')->index();
        });

        // payments already recorded: rows of one sale made together (same second, method, note, reference, receiver) were one collection
        $groups = DB::table('payments')
            ->where(fn ($q) => $q->whereNotNull('installment_id')->orWhereNotNull('sale_charge_id'))
            ->get(['id', 'sale_id', 'created_at', 'method', 'note', 'reference', 'received_by'])
            ->groupBy(fn ($p) => implode('|', [$p->sale_id, substr((string) $p->created_at, 0, 19), $p->method, $p->note, $p->reference, $p->received_by]));

        foreach ($groups as $rows) {
            if ($rows->count() > 1) {
                DB::table('payments')->whereIn('id', $rows->pluck('id'))->update(['batch_no' => (string) Str::uuid()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['batch_no']);
            $table->dropColumn('batch_no');
        });
    }
};
