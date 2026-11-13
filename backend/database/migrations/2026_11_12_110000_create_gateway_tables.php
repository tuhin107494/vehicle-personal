<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment module: gateways and the money that comes through them. Separate from the shop's own `payments` table,
 * which holds what customers pay a shop. Money is in whole paisa. Statuses only move forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_configs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('display_name', 100);
            $table->string('logo_path')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->enum('active_mode', ['sandbox', 'live'])->default('sandbox');
            // encrypted JSON (the model's cast); the key is APP_KEY, never in the repository
            $table->text('sandbox_credentials')->nullable();
            $table->text('live_credentials')->nullable();
            $table->unsignedBigInteger('min_amount_paisa')->nullable();
            $table->unsignedBigInteger('max_amount_paisa')->nullable();
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('fee_fixed_paisa')->default(0);
            // the addresses the gateway's webhooks come from, comma separated; blank = any (not every gateway publishes them)
            $table->string('webhook_ips', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->string('gateway_code', 30);
            $table->unsignedBigInteger('amount_paisa');
            $table->string('currency', 3)->default('BDT');
            // one key per click on "Pay": a repeat with the same key gets the same attempt back
            $table->string('idempotency_key', 64)->unique();
            $table->string('gateway_payment_id', 100)->nullable()->index();
            $table->enum('status', ['initiated', 'pending', 'success', 'failed', 'cancelled', 'expired'])->default('initiated');
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            // bank transfer: the receipt image the shop attached, and when it submitted the reference
            $table->string('proof_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['gateway_code', 'status']);
        });

        Schema::create('gateway_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->unique()->constrained('payment_attempts');
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->string('gateway_code', 30);
            $table->string('gateway_trx_id', 100);
            $table->unsignedBigInteger('amount_paisa');
            $table->unsignedBigInteger('fee_paisa')->default(0);
            $table->unsignedBigInteger('refunded_paisa')->default(0);
            $table->enum('status', ['success', 'partially_refunded', 'refunded'])->default('success');
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['gateway_code', 'gateway_trx_id']);
            $table->index(['shop_id', 'created_at']);
            $table->index(['gateway_code', 'paid_at']);
        });

        Schema::create('gateway_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_payment_id')->constrained();
            $table->foreignId('shop_id')->constrained();
            $table->unsignedBigInteger('amount_paisa');
            $table->string('reason');
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->string('gateway_refund_id', 100)->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->timestamps();
            $table->index(['gateway_payment_id', 'status']);
        });

        // append-only: raw requests, responses and webhooks, for support and reconciliation; no updated_at
        Schema::create('gateway_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->nullable()->constrained();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts');
            $table->string('gateway_code', 30);
            $table->enum('direction', ['request', 'response', 'webhook', 'error']);
            $table->string('action', 60);
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['gateway_code', 'created_at']);
            $table->index(['attempt_id']);
        });

        // double entry: every payment and refund writes a debit and a credit that balance
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->string('account', 40); // e.g. gateway:bkash, platform:revenue, platform:fees, shop:refunds
            $table->unsignedBigInteger('debit_paisa')->default(0);
            $table->unsignedBigInteger('credit_paisa')->default(0);
            $table->string('reference_type', 40); // gateway_payment, gateway_refund
            $table->unsignedBigInteger('reference_id');
            $table->string('memo')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['reference_type', 'reference_id']);
            $table->index(['account', 'created_at']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('gateway_logs');
        Schema::dropIfExists('gateway_refunds');
        Schema::dropIfExists('gateway_payments');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('gateway_configs');
    }
};
