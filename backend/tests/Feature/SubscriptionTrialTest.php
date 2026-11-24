<?php

namespace Tests\Feature;

use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\ShopService;
use App\Support\SignupConfirmation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTrialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Notification::fake();
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    /** An open shop, which starts on the 7-day trial. */
    private function shop(array $shop = []): Shop
    {
        return app(ShopService::class)->create(
            $shop + ['name' => 'Shop '.uniqid(), 'is_active' => true, 'approved_at' => now()],
            ['name' => 'Owner', 'email' => uniqid().'@t.test', 'password' => 'password'],
        );
    }

    private function current(Shop $shop): ?Subscription
    {
        return app(SubscriptionService::class)->current($shop->id);
    }

    private function endsIn(Shop $shop, string $when): void
    {
        $this->current($shop)->update(['ends_at' => now()->modify($when)]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_the_migration_adds_a_seven_day_trial_plan_and_a_hidden_legacy_plan(): void
    {
        $this->assertSame(7, Plan::where('slug', 'trial')->value('trial_days'));
        $this->assertTrue((bool) Plan::where('slug', 'trial')->value('is_active'));
        $this->assertFalse((bool) Plan::where('slug', 'legacy')->value('is_active'));
    }

    public function test_a_signed_up_shop_gets_seven_days_when_its_owner_confirms_the_email(): void
    {
        $this->postJson('/api/signup', ['name' => 'Rahim', 'shop_name' => 'Rahim Motors', 'phone' => '01700000000', 'email' => 'rahim@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
        $shop = Shop::where('name', 'Rahim Motors')->firstOrFail();
        $this->assertNull($this->current($shop)); // not started while the email is unconfirmed

        parse_str(parse_url(SignupConfirmation::url($shop->owner), PHP_URL_QUERY), $params);
        $this->postJson('/api/signup/confirm', $params)->assertOk()
            ->assertJsonPath('data.user.shop.subscription.status', 'trialing')
            ->assertJsonPath('data.user.shop.subscription.plan', 'Free trial');

        $sub = $this->current($shop);
        $this->assertSame('trialing', $sub->status);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $sub->ends_at->timestamp, 5);
        $this->assertEquals($sub->ends_at, $sub->trial_ends_at);
    }

    public function test_approving_a_pending_shop_starts_its_trial_once(): void
    {
        $shop = $this->shop(['is_active' => false, 'approved_at' => null]);
        $this->assertNull($this->current($shop));
        $this->as($this->superAdmin());

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'is_active' => true])->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing');
        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'is_active' => true])->assertOk();

        $this->assertSame(1, Subscription::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    public function test_a_shop_the_super_admin_creates_starts_on_the_trial(): void
    {
        $this->as($this->superAdmin());
        $this->postJson('/api/shops', ['name' => 'Made by admin', 'owner_name' => 'O', 'owner_email' => 'o@t.test', 'owner_password' => 'password1'])
            ->assertCreated()->assertJsonPath('data.subscription.status', 'trialing');
    }

    public function test_starting_a_trial_twice_keeps_the_first(): void
    {
        $shop = $this->shop();
        $first = $this->current($shop);

        app(SubscriptionService::class)->startTrial($shop);

        $this->assertSame([$first->id], Subscription::where('shop_id', $shop->id)->pluck('id')->all());
    }

    public function test_no_trial_plan_means_no_subscription_and_no_lock(): void
    {
        Plan::query()->update(['is_active' => false]);
        $shop = $this->shop();

        $this->assertNull($this->current($shop));
        $this->as($shop->owner);
        $this->getJson('/api/customers')->assertOk();
    }

    public function test_an_ended_trial_makes_the_shop_read_only(): void
    {
        $shop = $this->shop();
        $this->as($shop->owner);
        $customer = $this->postJson('/api/customers', ['name' => 'Kept', 'phone' => '01700000001'])->assertCreated()->json('data.id');
        $this->endsIn($shop, '-1 minute');

        // they can still sign in, and the app can say why it is read-only
        auth()->forgetGuards();
        $this->postJson('/api/login', ['email' => $shop->owner->email, 'password' => 'password'])->assertOk();
        $this->as($shop->owner);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.shop.subscription.status', 'expired');

        // viewing (and so exporting) still works, data kept
        $this->getJson('/api/customers')->assertOk();
        $this->getJson("/api/customers/{$customer}")->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/sales')->assertOk();
        $this->getJson('/api/vehicle-categories')->assertOk();

        // creating, editing and deleting are refused, in the shop group and outside it
        $this->postJson('/api/customers', ['name' => 'X', 'phone' => '01700000002'])->assertForbidden()
            ->assertJsonPath('message', SubscriptionService::ENDED_MESSAGE);
        $this->putJson("/api/customers/{$customer}", ['name' => 'Changed', 'phone' => '01700000001'])->assertForbidden();
        $this->deleteJson("/api/customers/{$customer}")->assertForbidden();
        $this->postJson('/api/sales', [])->assertForbidden();
        $this->postJson('/api/users', [])->assertForbidden(); // owner screens too
        $this->postJson('/api/vehicle-categories', ['name' => 'X'])->assertForbidden();
        $this->assertDatabaseHas('customers', ['id' => $customer, 'name' => 'Kept']);
    }

    public function test_a_running_trial_works_and_the_super_admin_is_never_locked(): void
    {
        $shop = $this->shop();
        $this->as($shop->owner);
        $this->getJson('/api/customers')->assertOk();

        $this->endsIn($shop, '-1 day');
        $this->as($this->superAdmin());
        $this->getJson('/api/shops')->assertOk()->assertJsonPath('data.0.subscription.status', 'expired');
        $this->getJson('/api/vehicle-categories')->assertOk();
    }

    public function test_the_super_admin_extends_from_now_when_ended_and_from_the_end_when_running(): void
    {
        $ended = $this->shop();
        $this->endsIn($ended, '-3 days');
        $running = $this->shop();
        $this->endsIn($running, '+2 days');
        $this->as($this->superAdmin());

        $this->postJson("/api/shops/{$ended->id}/subscription/extend", ['days' => 7])->assertOk()
            ->assertJsonPath('data.subscription.status', 'trialing');
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $this->current($ended)->ends_at->timestamp, 5);
        $this->assertEquals($this->current($ended)->ends_at, $this->current($ended)->trial_ends_at); // a trial stays a trial

        $this->postJson("/api/shops/{$running->id}/subscription/extend", ['days' => 7])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(9)->timestamp, $this->current($running)->ends_at->timestamp, 5);

        $this->as($ended->owner); // an extended shop can make changes again
        $this->postJson('/api/customers', ['name' => 'Back', 'phone' => '01700000003'])->assertCreated();
    }

    public function test_extending_an_ended_paid_subscription_makes_it_active(): void
    {
        $shop = $this->shop();
        $this->current($shop)->update(['status' => 'expired', 'ends_at' => now()->subDay()]);
        $this->as($this->superAdmin());

        $this->postJson("/api/shops/{$shop->id}/subscription/extend", ['days' => 30])->assertOk()
            ->assertJsonPath('data.subscription.status', 'active');
    }

    public function test_extending_validates_days_and_needs_a_subscription(): void
    {
        Plan::query()->update(['is_active' => false]);
        $none = $this->shop();
        Plan::where('slug', 'trial')->update(['is_active' => true]);
        $shop = $this->shop();
        $this->as($this->superAdmin());

        $this->postJson("/api/shops/{$none->id}/subscription/extend", ['days' => 7])->assertStatus(409);
        foreach ([0, 366, 'x', null] as $days) {
            $this->postJson("/api/shops/{$shop->id}/subscription/extend", ['days' => $days])->assertStatus(422)->assertJsonValidationErrors('days');
        }
    }

    public function test_only_the_super_admin_can_extend(): void
    {
        $shop = $this->shop();
        $before = $this->current($shop)->ends_at;
        $this->as($shop->owner);

        $this->postJson("/api/shops/{$shop->id}/subscription/extend", ['days' => 7])->assertForbidden();
        $this->assertEquals($before, $this->current($shop)->ends_at);
    }
}
