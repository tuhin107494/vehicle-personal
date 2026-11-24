<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installments hold both schedules: a customer's (plan_id: what the customer pays the shop) and an investor's
 * (investor_sale_id: what the shop pays the investor back). Every row has exactly one of the two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->change();
            $table->foreignId('investor_sale_id')->nullable()->after('plan_id')->constrained('investor_sales');
        });

        // the investor sales made so far get their installments, with what has been paid on them. Written with plain
        // queries (not the app's models or services), so what this fills in stays the same when the app code changes.
        DB::table('investor_sales')->orderBy('id')->chunkById(200, function ($sales) {
            foreach ($sales as $sale) {
                $this->fill($sale);
            }
        });
    }

    /**
     * The investor sale's schedule: its total split into equal installments (in paisa, the last one taking what is left)
     * a month or a week apart, filled in order by what was paid back (principal and profit).
     */
    private function fill(object $sale): void
    {
        $count = (int) $sale->number_of_installments;
        $total = (int) round((float) $sale->total_payable * 100);

        if ($count < 1 || $total <= 0 || DB::table('installments')->where('investor_sale_id', $sale->id)->exists()) {
            return; // no plan (the investor collects from the customer), or already filled in
        }

        $paid = DB::table('investor_settlements')->where('investor_sale_id', $sale->id)->whereIn('type', ['principal_return', 'profit_share']);
        $left = (int) round((float) (clone $paid)->sum('amount') * 100);
        $lastPaid = Carbon::parse((clone $paid)->max('date') ?? now()->toDateString())->startOfDay()->toDateTimeString();
        $each = (int) round($total / $count);
        $start = Carbon::parse($sale->start_date);
        $today = now()->startOfDay();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $amount = $i === $count - 1 ? $total - $each * ($count - 1) : $each;
            $due = $sale->frequency === 'weekly' ? $start->copy()->addWeeks($i) : $start->copy()->addMonthsNoOverflow($i);
            $part = min($left, $amount);
            $left -= $part;

            $status = match (true) {
                $part >= $amount => 'paid',
                $sale->status !== 'active' => 'cancelled',
                $due->lt($today) => 'overdue',
                default => $part > 0 ? 'partial' : 'pending',
            };

            $rows[] = [
                'shop_id' => $sale->shop_id, 'plan_id' => null, 'investor_sale_id' => $sale->id, 'seq_no' => $i + 1,
                'due_date' => $due->toDateString(), 'amount' => $amount / 100, 'paid_amount' => $part / 100,
                'status' => $status, 'paid_at' => $status === 'paid' ? $lastPaid : null, 'created_at' => now(), 'updated_at' => now(),
            ];
        }

        DB::table('installments')->insert($rows);
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_sale_id');
        });
    }
};
