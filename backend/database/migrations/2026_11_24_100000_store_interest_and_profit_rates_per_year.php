<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Interest on an installment plan, and an investor's profit on an investor sale, are now flat yearly rates: 20% over
 * 24 months is 40% of the amount. Plans saved before stored the rate for the whole plan (20% over 24 months was 20%).
 * The amounts charged never change: every rate is worked out again from the amount actually stored, so it reads right
 * as a yearly rate. Doing it from the amount gives the same rate on rows already saved the new way, so running this
 * twice changes nothing.
 *
 * Plain queries only (no models or services), so what this writes does not change when the app code does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (float $amount, float $base, float $years) => $amount / $base * 100 / $years);
    }

    /** Back to a rate for the whole plan. */
    public function down(): void
    {
        $this->rewrite(fn (float $amount, float $base, float $years) => $amount / $base * 100);
    }

    /** @param  callable(float, float, float): float  $rateOf  the rate from the amount, the amount it is on, and the plan's length in years */
    private function rewrite(callable $rateOf): void
    {
        $years = fn (object $row) => max((int) $row->number_of_installments, 1) / ($row->frequency === 'weekly' ? 52 : 12);

        // interest is on what is paid in installments: the principal less the promised amount (paid outside them)
        DB::table('installment_plans')->orderBy('id')
            ->select(['id', 'principal', 'promise_amount', 'interest_amount', 'number_of_installments', 'frequency'])
            ->chunkById(500, function ($plans) use ($rateOf, $years) {
                foreach ($plans as $plan) {
                    $base = (float) $plan->principal - (float) ($plan->promise_amount ?? 0);
                    $rate = $base > 0 ? round($rateOf((float) $plan->interest_amount, $base, $years($plan)), 2) : 0;
                    DB::table('installment_plans')->where('id', $plan->id)->update(['interest_rate' => min($rate, 9999.99)]);
                }
            });

        // the investor's profit is on their money on the plan (none when there is no plan: nothing is paid back)
        DB::table('investor_sales')->orderBy('id')
            ->select(['id', 'principal', 'profit_amount', 'number_of_installments', 'frequency'])
            ->chunkById(500, function ($sales) use ($rateOf, $years) {
                foreach ($sales as $sale) {
                    $base = (float) $sale->principal;
                    $rate = $base > 0 ? round($rateOf((float) $sale->profit_amount, $base, $years($sale)), 2) : 0;
                    DB::table('investor_sales')->where('id', $sale->id)->update(['profit_rate' => min($rate, 9999.99)]);
                }
            });
    }
};
