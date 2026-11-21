<?php

use App\Models\InvestorSale;
use App\Services\InvestorSaleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // the investor sales made so far get their installments, with what has been paid on them
        $service = app(InvestorSaleService::class);
        InvestorSale::withoutGlobalScopes()->orderBy('id')->each(fn (InvestorSale $x) => $service->syncInstallments($x));
    }

    public function down(): void
    {
        Schema::table('installments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('investor_sale_id');
        });
    }
};
