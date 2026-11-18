<?php

namespace Tests\Feature;

use App\Exceptions\BusinessRuleException;
use App\Models\Billing\Coupon;
use App\Models\Billing\Feature;
use App\Models\Billing\FeatureUsage;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\EntitlementCache;
use App\Services\Billing\FeatureGateService;
use App\Services\Billing\SubscriptionService;
use App\Services\ShopService;
use Database\Seeders\BillingSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Phase 3 of the subscription module: the services, the cached entitlements and what the middleware and the limits refuse. */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->seed(BillingSeeder::class);
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = User::where('shop_id', $this->shop->id)->firstOrFail();
        $this->subscriptions = app(SubscriptionService::class);
        Sanctum::actingAs($this->owner);
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    private function current(): Subscription
    {
        return $this->subscriptions->current($this->shop->id);
    }

    /** Puts the shop on a running, paid plan with a known period (1 Nov → 1 Dec). */
    private function activeOn(string $slug): Subscription
    {
        $invoice = $this->subscriptions->subscribe($this->shop, $this->plan($slug))['invoice'];

        return $this->subscriptions->renew($invoice);
    }

    /** Changes one value of a plan's feature the way the super admin would, and drops the cached entitlements. */
    private function setFeature(string $slug, string $key, ?string $value): void
    {
        DB::table('plan_features')->where('plan_id', $this->plan($slug)->id)->where('feature_id', Feature::where('key', $key)->value('id'))->update(['value' => $value]);
        app(EntitlementCache::class)->forget($this->shop->id);
    }

    public function test_a_new_shop_starts_on_a_trial_of_the_cheapest_plan_with_one(): void
    {
        $sub = $this->current();
        $this->assertSame(['trialing', 'starter'], [$sub->status, $sub->plan->slug]);
        $this->assertSame('2026-11-15', $sub->trial_ends_at->toDateString());
        $this->assertSame('2026-11-15', $sub->ends_at->toDateString());

        // a trial may use the app
        $this->postJson('/api/customers', ['name' => 'Buyer', 'phone' => '01700000001'])->assertCreated();
    }

    public function test_subscribing_makes_an_invoice_and_paying_it_starts_the_period_once(): void
    {
        ['subscription' => $sub, 'invoice' => $invoice] = $this->subscriptions->subscribe($this->shop, $this->plan('business'));

        $this->assertSame('trialing', $sub->status); // nothing starts until the money arrives
        $this->assertSame(['unpaid', 150000, 150000, 0], [$invoice->status, $invoice->amount_paisa, $invoice->total_paisa, $invoice->discount_paisa]);
        $this->assertSame('INV-202611-000001', $invoice->invoice_no);
        $this->assertSame('2026-12-01', $invoice->period_ends_at->toDateString());

        $sub = $this->subscriptions->renew($invoice);
        $this->assertSame(['active', 'business', '2026-11-01', '2026-12-01'], [$sub->status, $sub->plan->slug, $sub->starts_at->toDateString(), $sub->ends_at->toDateString()]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'paid']);

        // the payment event may arrive twice: the second changes nothing
        $this->subscriptions->renew($invoice->fresh());
        $this->assertSame('2026-12-01', $this->current()->ends_at->toDateString());
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'subscription.changed']);
    }

    public function test_an_upgrade_switches_at_once_and_charges_exactly_the_prorated_difference(): void
    {
        $this->activeOn('starter'); // 50,000 paisa for 1 Nov → 1 Dec (30 days)
        $this->travelTo('2026-11-16 10:00:00'); // half the period left

        ['subscription' => $sub, 'invoice' => $invoice] = $this->subscriptions->upgrade($this->shop, $this->plan('business'));

        // (150,000 − 50,000) × 15/30 = 50,000 paisa, no float drift
        $this->assertSame(50000, $invoice->total_paisa);
        $this->assertSame(['business', 'active', '2026-12-01'], [$sub->plan->slug, $sub->status, $sub->ends_at->toDateString()]);
        $this->assertSame('2026-11-16', $invoice->period_starts_at->toDateString());

        // paying a mid-period invoice leaves the period alone
        $this->subscriptions->renew($invoice);
        $this->assertSame(['2026-11-01', '2026-12-01'], [$this->current()->starts_at->toDateString(), $this->current()->ends_at->toDateString()]);

        $this->expectException(BusinessRuleException::class);
        $this->subscriptions->upgrade($this->shop, $this->plan('starter'));
    }

    public function test_a_downgrade_waits_for_the_period_to_end_then_grace_then_expiry(): void
    {
        $this->activeOn('business');
        $sub = $this->subscriptions->downgrade($this->shop, $this->plan('starter'));
        $this->assertSame(['business', 'starter'], [$sub->plan->slug, $sub->pendingPlan->slug]);

        // still before the end: nothing moves
        $this->assertSame('active', $this->subscriptions->rollStatus($sub, now()->parse('2026-11-30')));

        // the period ends: the cheaper plan takes over and a 7-day grace starts
        $this->assertSame('past_due', $this->subscriptions->rollStatus($sub, now()->parse('2026-12-01 10:00:01')));
        $sub = $this->current();
        $this->assertSame(['starter', null, '2026-12-08'], [$sub->plan->slug, $sub->pending_plan_id, $sub->grace_ends_at->toDateString()]);
        $this->assertTrue($sub->isUsable());

        $this->assertSame('past_due', $this->subscriptions->rollStatus($sub, now()->parse('2026-12-07')));
        $this->assertSame('expired', $this->subscriptions->rollStatus($sub, now()->parse('2026-12-08 10:00:01')));
    }

    public function test_cancelling_keeps_the_plan_until_the_period_ends(): void
    {
        $this->activeOn('starter');
        $sub = $this->subscriptions->cancel($this->shop);
        $this->assertSame(['active', false], [$sub->status, $sub->auto_renew]);
        $this->assertNotNull($sub->cancelled_at);

        $this->assertSame('cancelled', $this->subscriptions->rollStatus($sub, now()->parse('2026-12-02')));
        $this->assertFalse($this->current()->isUsable());
    }

    public function test_an_expired_shop_may_read_but_not_write(): void
    {
        $this->activeOn('starter');
        Subscription::withoutGlobalScopes()->where('shop_id', $this->shop->id)->update(['status' => 'expired']);
        app(EntitlementCache::class)->forget($this->shop->id);

        $this->getJson('/api/customers')->assertOk();
        $this->postJson('/api/customers', ['name' => 'Buyer', 'phone' => '01700000001'])->assertStatus(402)
            ->assertJsonPath('message', 'Your subscription has expired. Renew it to continue making changes.');
        $this->assertDatabaseCount('customers', 0);
        // the billing area stays open: the request reaches validation (422), it is not turned away with 402
        $this->postJson('/api/billing/subscribe', [])->assertUnprocessable();
    }

    public function test_the_plan_decides_which_features_a_shop_may_open(): void
    {
        $this->activeOn('starter'); // starter: no investors, no reports
        $this->getJson('/api/investors')->assertStatus(402);
        $this->getJson('/api/reports/stock')->assertStatus(402);
        $this->postJson('/api/investors', ['name' => 'Inv', 'phone' => '01800000001'])->assertStatus(402);
        $this->assertDatabaseCount('investors', 0);
        $this->getJson('/api/customers')->assertOk();

        // an upgrade clears the cached entitlements: the same requests now pass
        $this->subscriptions->upgrade($this->shop, $this->plan('business'));
        $this->getJson('/api/investors')->assertOk();
        $this->getJson('/api/reports/stock')->assertOk();
    }

    public function test_staff_vehicle_and_monthly_sale_limits_are_enforced_in_the_services(): void
    {
        $this->activeOn('starter');
        $gate = app(FeatureGateService::class);

        // max_staff 2: the owner and one more
        $user = ['name' => 'Staff', 'email' => 'staff@a.test', 'password' => 'password', 'role' => 'moderator'];
        $this->postJson('/api/users', $user)->assertCreated();
        $this->postJson('/api/users', ['email' => 'staff2@a.test'] + $user)->assertStatus(409)
            ->assertJsonPath('message', 'Your plan allows up to 2 staff accounts. Upgrade your plan to add more.');
        $this->assertSame(2, User::where('shop_id', $this->shop->id)->count());
        $this->assertSame(0, $gate->remaining($this->shop->id, 'max_staff'));

        // max_vehicles_in_stock 1: the second purchase is refused and writes nothing
        $this->setFeature('starter', 'max_vehicles_in_stock', '1');
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01000000001']);
        $buy = fn (string $ch) => $this->postJson('/api/purchases', ['vehicle_type' => 'motorcycle', 'chassis_no' => $ch, 'engine_no' => "EN-$ch", 'brand' => 'Honda', 'model' => 'CB', 'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 100000, 'paid_amount' => 100000]);
        $buy('CH-1')->assertCreated();
        $buy('CH-2')->assertStatus(409);
        $this->assertDatabaseCount('vehicles', 1);
        $this->assertDatabaseCount('purchases', 1);

        // max_sales_per_month 1: the counter is atomic and seeded from the sales table
        $this->setFeature('starter', 'max_vehicles_in_stock', null);
        $buy('CH-2')->assertCreated();
        $this->setFeature('starter', 'max_sales_per_month', '1');
        $customer = $this->postJson('/api/customers', ['name' => 'Buyer', 'phone' => '01700000001'])->json('data.id');
        $sell = fn (int $vehicle) => $this->postJson('/api/sales', ['vehicle_id' => $vehicle, 'customer_id' => $customer, 'sale_date' => today()->toDateString(), 'sale_price' => 120000, 'payment_type' => 'cash', 'down_payment' => 120000]);
        $sell(1)->assertCreated();
        $this->assertSame(1, $gate->used($this->shop->id, 'max_sales_per_month'));
        $sell(2)->assertStatus(409);
        $this->assertDatabaseCount('sales', 1);

        // the counter survives a cache reset: it is rebuilt from the sales of the month
        app('cache')->flush();
        $this->assertSame(1, $gate->used($this->shop->id, 'max_sales_per_month'));
        $gate->syncUsage($this->shop->id);
        $usage = FeatureUsage::withoutGlobalScopes()->where('shop_id', $this->shop->id)->where('feature_key', 'max_sales_per_month')->firstOrFail();
        $this->assertSame([1, '2026-11-01', '2026-11-30'], [$usage->used, $usage->period_start->toDateString(), $usage->period_end->toDateString()]);
    }

    public function test_a_feature_the_plan_does_not_mention_is_allowed(): void
    {
        // the legacy plan has no feature rows at all: existing shops keep everything
        $legacy = Plan::where('slug', 'legacy')->firstOrFail();
        Subscription::withoutGlobalScopes()->where('shop_id', $this->shop->id)->update(['plan_id' => $legacy->id, 'status' => 'active']);
        app(EntitlementCache::class)->forget($this->shop->id);

        $gate = app(FeatureGateService::class);
        $this->assertTrue($gate->canUse($this->shop->id, 'investors'));
        $this->assertNull($gate->limit($this->shop->id, 'max_staff'));
        $this->assertNull($gate->remaining($this->shop->id, 'max_staff'));
        $this->getJson('/api/reports/stock')->assertOk();
    }

    public function test_coupons_take_an_exact_discount_and_refuse_when_spent(): void
    {
        $billing = app(BillingService::class);
        $percent = Coupon::create(['code' => 'TEN', 'type' => 'percent', 'value' => 10]);
        $fixed = Coupon::create(['code' => 'BIG', 'type' => 'fixed', 'value' => 999999, 'max_uses' => 1]);

        $this->assertSame(15000, $billing->applyCoupon($percent, 150000));
        $this->assertSame(150000, $billing->applyCoupon($fixed, 150000)); // never more than the amount

        ['invoice' => $invoice] = $this->subscriptions->subscribe($this->shop, $this->plan('business'), $fixed);
        $this->assertSame([150000, 150000, 0, 'paid'], [$invoice->amount_paisa, $invoice->discount_paisa, $invoice->total_paisa, $invoice->status]);
        $this->assertSame('active', $this->current()->status); // nothing to pay: the period started at once
        $this->assertDatabaseHas('coupons', ['id' => $fixed->id, 'used_count' => 1]);

        $this->expectException(BusinessRuleException::class);
        $billing->applyCoupon($fixed->fresh(), 1000);
    }

    public function test_the_super_admin_can_extend_a_subscription_and_it_is_audited(): void
    {
        $this->activeOn('starter');
        $admin = User::factory()->create(['role' => 'super_admin', 'shop_id' => null]);

        $sub = $this->subscriptions->extendManually($this->shop, now()->parse('2027-03-01'), $admin, 'Paid in cash at the office');

        $this->assertSame(['active', '2027-03-01'], [$sub->status, $sub->ends_at->toDateString()]);
        $this->assertDatabaseHas('admin_audit_logs', ['user_id' => $admin->id, 'action' => 'subscription.extended', 'subject_type' => 'subscriptions', 'subject_id' => $sub->id]);

        $this->expectException(BusinessRuleException::class);
        $this->subscriptions->extendManually($this->shop, now()->subDay(), $admin);
    }

    public function test_another_shop_cannot_see_or_use_this_shops_subscription(): void
    {
        $this->activeOn('business');
        $other = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'B', 'email' => 'b@b.test', 'password' => 'password']);

        auth()->forgetGuards();
        Sanctum::actingAs(User::where('shop_id', $other->id)->firstOrFail());
        $this->assertSame('trialing', $this->subscriptions->current($other->id)->status);
        $this->assertSame(1, Subscription::count()); // the scope shows shop B only its own
        $this->getJson('/api/investors')->assertStatus(402); // shop B's starter trial, not shop A's business plan
    }
}
