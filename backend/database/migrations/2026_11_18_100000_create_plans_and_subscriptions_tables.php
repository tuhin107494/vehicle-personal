<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a shop is subscribed to and until when. The two tables match the full subscription module (subscription-module
 * branch) column for column, so payments, invoices and plan features can be added later without reshaping them.
 * Money is kept in whole paisa (integers).
 *
 * Every shop that exists today goes on an open "legacy" plan for 10 years, so nobody is locked out on deploy.
 * A "trial" plan (free, 7 days) is what new shops start on.
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
            $table->unsignedBigInteger('original_price_paisa')->nullable();
            $table->string('currency', 3)->default('BDT');
            $table->enum('billing_interval', ['monthly', 'quarterly', 'half_yearly', 'yearly'])->default('monthly');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
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
            $table->unsignedSmallInteger('last_reminder_days')->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'status']);
            $table->index(['status', 'ends_at']);
            $table->index(['status', 'grace_ends_at']);
        });

        $now = now();
        $plan = fn (array $row) => DB::table('plans')->insertGetId($row + [
            'price_paisa' => 0, 'currency' => 'BDT', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $plan([
            'name' => 'Free trial', 'slug' => 'trial', 'billing_interval' => 'monthly', 'trial_days' => 7, 'is_active' => true, 'sort_order' => 0,
            'description' => 'What every new shop starts on.',
        ]);
        $legacyId = $plan([
            'name' => 'Legacy', 'slug' => 'legacy', 'billing_interval' => 'yearly', 'trial_days' => 0, 'is_active' => false, 'sort_order' => 0,
            'description' => 'Shops that existed before subscriptions. Not offered to new shops.',
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
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
