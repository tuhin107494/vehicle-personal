<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the scheduler needs: which renewal reminder a subscription last got, and a place for the daily settlement
 * check to flag a payment whose ledger or gateway record does not match, for the super admin to look at.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL does not roll back DDL: a run that failed half way may have left the column and the (empty) table behind
        if (! Schema::hasColumn('subscriptions', 'last_reminder_days')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                // the reminder window (7, 3 or 1 days before the end) last sent for the current period; cleared when the period moves
                $table->unsignedSmallInteger('last_reminder_days')->nullable()->after('pending_plan_id');
            });
        }

        Schema::dropIfExists('reconciliation_mismatches');

        Schema::create('reconciliation_mismatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained();
            $table->string('gateway_code', 30);
            $table->string('reference_type', 40); // gateway_payment, payment_attempt
            $table->unsignedBigInteger('reference_id');
            $table->string('kind', 30); // amount, status, ledger
            $table->unsignedBigInteger('expected_paisa')->nullable();
            $table->unsignedBigInteger('actual_paisa')->nullable();
            $table->string('message');
            $table->json('details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->string('note')->nullable();
            $table->timestamps();
            // named by hand: the generated names pass MySQL's 64-character limit for identifiers
            $table->index(['reference_type', 'reference_id', 'kind'], 'recon_mismatch_reference_index');
            $table->index(['gateway_code', 'resolved_at', 'created_at'], 'recon_mismatch_gateway_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_mismatches');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('last_reminder_days');
        });
    }
};
