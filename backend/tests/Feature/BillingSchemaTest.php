<?php

namespace Tests\Feature;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayLog;
use App\Models\User;
use App\Services\ShopService;
use App\Support\FeatureCatalog;
use Database\Seeders\BillingSeeder;
use Database\Seeders\GatewayConfigSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Phase 2 of the subscription module: the tables, models and seeders, before any endpoint exists. */
class BillingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_gives_every_existing_shop_a_legacy_subscription(): void
    {
        // the data step ran on an empty shops table; what it does is checked by running it again on shops that exist now
        $this->seed(PermissionSeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Old shop'], ['name' => 'Owner', 'email' => 'owner@old.test', 'password' => 'password']);
        $this->assertDatabaseHas('plans', ['slug' => 'legacy', 'is_active' => false]);

        $migration = require base_path('database/migrations/2026_11_12_100000_create_billing_tables.php');
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('subscriptions', ['shop_id' => $shop->id, 'status' => 'active', 'auto_renew' => false]);
        $this->assertSame(1, Subscription::withoutGlobalScopes()->count());
        $this->assertTrue(Subscription::withoutGlobalScopes()->first()->ends_at->gt(now()->addYears(9)));
    }

    public function test_seeders_create_features_plans_and_gateways_once_and_keep_edits(): void
    {
        $this->seed(BillingSeeder::class);
        $this->seed(GatewayConfigSeeder::class);

        $this->assertSame(count(FeatureCatalog::all()), Feature::count());
        $this->assertDatabaseHas('plans', ['slug' => 'starter', 'price_paisa' => 50000, 'billing_interval' => 'monthly']);
        $this->assertDatabaseCount('gateway_configs', 4);
        $this->assertDatabaseHas('gateway_configs', ['code' => 'bkash', 'is_active' => false, 'active_mode' => 'sandbox']);

        // a limit left NULL means unlimited; a boolean is "1"/"0"
        $business = Plan::where('slug', 'business')->firstOrFail();
        $this->assertNull($business->features()->where('key', 'max_sales_per_month')->first()->pivot->value);
        $this->assertSame('1', $business->features()->where('key', 'investors')->first()->pivot->value);

        // re-running never touches what the super admin changed
        Plan::where('slug', 'starter')->update(['price_paisa' => 70000]);
        GatewayConfig::where('code', 'bkash')->update(['is_active' => true]);
        $this->seed(BillingSeeder::class);
        $this->seed(GatewayConfigSeeder::class);
        $this->assertDatabaseHas('plans', ['slug' => 'starter', 'price_paisa' => 70000]);
        $this->assertDatabaseHas('gateway_configs', ['code' => 'bkash', 'is_active' => true]);
        $this->assertDatabaseCount('gateway_configs', 4);
    }

    public function test_gateway_credentials_are_encrypted_at_rest_and_never_serialised(): void
    {
        $config = GatewayConfig::create(['code' => 'bkash', 'display_name' => 'bKash', 'sandbox_credentials' => ['app_key' => 'k1', 'app_secret' => 's3cret']]);

        $raw = DB::table('gateway_configs')->where('id', $config->id)->value('sandbox_credentials');
        $this->assertStringNotContainsString('s3cret', $raw);
        $this->assertSame('s3cret', $config->fresh()->sandbox_credentials['app_secret']);
        $this->assertSame(['app_key' => 'k1', 'app_secret' => 's3cret'], $config->fresh()->credentials());

        $this->assertArrayNotHasKey('sandbox_credentials', $config->toArray());
        $this->assertArrayNotHasKey('live_credentials', $config->toArray());
        $this->assertStringNotContainsString('s3cret', json_encode($config));
    }

    public function test_gateway_logs_blank_secret_values_at_any_depth(): void
    {
        $scrubbed = GatewayLog::scrub(['amount' => 100, 'headers' => ['Authorization' => 'Bearer abc', 'X-App-Key' => 'k'], 'app_secret' => 'x']);

        $this->assertSame(['amount' => 100, 'headers' => ['Authorization' => '[hidden]', 'X-App-Key' => '[hidden]'], 'app_secret' => '[hidden]'], $scrubbed);
    }

    public function test_subscriptions_and_attempts_are_shop_scoped(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(BillingSeeder::class);
        $a = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'A', 'email' => 'a@a.test', 'password' => 'password']);
        $b = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'B', 'email' => 'b@b.test', 'password' => 'password']);
        $plan = Plan::where('slug', 'starter')->firstOrFail();

        Sanctum::actingAs(User::where('shop_id', $a->id)->firstOrFail());
        $sub = Subscription::create(['plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $this->assertSame($a->id, $sub->shop_id); // stamped by BelongsToShop

        auth()->forgetGuards();
        Sanctum::actingAs(User::where('shop_id', $b->id)->firstOrFail());
        $this->assertSame([$b->id], Subscription::pluck('shop_id')->unique()->all()); // shop B sees only its own (the trial it started on)
        $this->assertNull(Subscription::find($sub->id));
    }
}
