<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subscription module: what a shop pays the platform for. Money is kept in whole paisa (integers), so sums are exact.
 * Existing shops get a "legacy" subscription on an open plan, so turning enforcement on later locks nobody out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->unsignedBigInteger('price_paisa')->default(0);
            $table->string('currency', 3)->default('BDT');
            $table->enum('billing_interval', ['monthly', 'yearly'])->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->enum('type', ['boolean', 'limit']);
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            // "1"/"0" for a boolean feature, a number for a limit; NULL on a limit means unlimited
            $table->string('value', 20)->nullable();
            $table->unique(['plan_id', 'feature_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->enum('status', ['trialing', 'active', 'past_due', 'expired', 'cancelled'])->default('trialing');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            // a downgrade waits for the period to end: the plan it will move to then
            $table->foreignId('pending_plan_id')->nullable()->constrained('plans');
            $table->timestamps();
            $table->index(['shop_id', 'status']);
            $table->index(['status', 'ends_at']);
            $table->index(['status', 'grace_ends_at']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->enum('type', ['percent', 'fixed']);
            // percent: whole percent (10 = 10%); fixed: paisa
            $table->unsignedBigInteger('value');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('subscription_id')->constrained();
            $table->foreignId('plan_id')->constrained();
            $table->foreignId('coupon_id')->nullable()->constrained();
            $table->string('invoice_no', 30)->unique();
            $table->unsignedBigInteger('amount_paisa');
            $table->unsignedBigInteger('discount_paisa')->default(0);
            $table->unsignedBigInteger('total_paisa');
            $table->string('currency', 3)->default('BDT');
            $table->enum('status', ['unpaid', 'paid', 'void'])->default('unpaid');
            // what the invoice buys: the period the subscription will cover once it is paid
            $table->timestamp('period_starts_at');
            $table->timestamp('period_ends_at');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'status', 'created_at']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('feature_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->unsignedBigInteger('used')->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamps();
            $table->unique(['shop_id', 'feature_key', 'period_start']);
        });

        // every shop that exists today keeps working: an open "legacy" plan, far in the future, that the super admin can change
        $now = now();
        $legacyId = DB::table('plans')->insertGetId([
            'name' => 'Legacy', 'slug' => 'legacy', 'price_paisa' => 0, 'currency' => 'BDT', 'billing_interval' => 'yearly',
            'trial_days' => 0, 'is_active' => false, 'sort_order' => 0,
            'description' => 'Shops that existed before subscriptions. Not offered to new shops.',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $rows = DB::table('shops')->pluck('id')
            ->map(fn ($id) => [
                'shop_id' => $id, 'plan_id' => $legacyId, 'status' => 'active', 'starts_at' => $now, 'ends_at' => $now->copy()->addYears(10),
                'auto_renew' => false, 'created_at' => $now, 'updated_at' => $now,
            ])->all();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('subscriptions')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usage');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plans');
    }
};
