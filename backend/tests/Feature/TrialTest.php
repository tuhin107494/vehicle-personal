<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Services\ShopService;
use App\Services\TrialService;
use App\Support\SignupConfirmation;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrialTest extends TestCase
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

    private function shop(array $shop = []): Shop
    {
        return app(ShopService::class)->create($shop + ['name' => 'Shop '.uniqid()], ['name' => 'Owner', 'email' => uniqid().'@t.test', 'password' => 'password']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    public function test_a_signed_up_shop_gets_seven_days_when_its_owner_confirms_the_email(): void
    {
        $this->postJson('/api/signup', ['name' => 'Rahim', 'shop_name' => 'Rahim Motors', 'phone' => '01700000000', 'email' => 'rahim@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertCreated();
        $shop = Shop::where('name', 'Rahim Motors')->firstOrFail();
        $this->assertNull($shop->trial_ends_at); // not started while the email is unconfirmed

        $owner = $shop->owner;
        $url = SignupConfirmation::url($owner);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->postJson('/api/signup/confirm', $params)->assertOk();

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $shop->fresh()->trial_ends_at->timestamp, 5);
    }

    public function test_approving_a_pending_shop_starts_its_trial(): void
    {
        $shop = $this->shop(['is_active' => false, 'approved_at' => null]);
        $this->as($this->superAdmin());

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'is_active' => true])->assertOk();

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $shop->fresh()->trial_ends_at->timestamp, 5);
    }

    public function test_the_super_admin_sets_or_clears_the_trial_date_when_editing_a_shop(): void
    {
        $shop = $this->shop(['approved_at' => now(), 'is_active' => true]);
        $this->as($this->superAdmin());
        $date = now()->addDays(10)->toDateString();

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'trial_ends_at' => $date])->assertOk();
        $this->assertSame($date.' 23:59:59', $shop->fresh()->trial_ends_at->format('Y-m-d H:i:s')); // the whole day counts

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name])->assertOk(); // not sent: left as it is
        $this->assertNotNull($shop->fresh()->trial_ends_at);

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'trial_ends_at' => null])->assertOk();
        $this->assertNull($shop->fresh()->trial_ends_at);

        $this->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'trial_ends_at' => 'soon'])->assertStatus(422)->assertJsonValidationErrors('trial_ends_at');
    }

    public function test_shops_made_by_the_super_admin_have_no_limit(): void
    {
        $this->as($this->superAdmin());
        $this->postJson('/api/shops', ['name' => 'Made by admin', 'owner_name' => 'O', 'owner_email' => 'o@t.test', 'owner_password' => 'password1'])->assertCreated();

        $this->assertNull(Shop::where('name', 'Made by admin')->value('trial_ends_at'));
    }

    public function test_an_ended_trial_blocks_sign_in_and_shop_screens(): void
    {
        $shop = $this->shop(['approved_at' => now(), 'is_active' => true, 'trial_ends_at' => now()->subMinute()]);
        $owner = $shop->owner;

        $this->postJson('/api/login', ['email' => $owner->email, 'password' => 'password'])
            ->assertStatus(422)->assertJsonValidationErrors(['email' => TrialService::ENDED_MESSAGE]);

        $this->as($owner);
        $this->getJson('/api/customers')->assertForbidden()->assertJsonPath('message', TrialService::ENDED_MESSAGE);
        $this->getJson('/api/vehicle-categories')->assertForbidden(); // routes outside the shop group are locked too
        $this->getJson('/api/me')->assertOk(); // the app can still show who they are and why they are locked
    }

    public function test_a_running_trial_and_a_shop_without_a_trial_work_normally(): void
    {
        $trial = $this->shop(['approved_at' => now(), 'is_active' => true, 'trial_ends_at' => now()->addDay()]);
        $free = $this->shop(['approved_at' => now(), 'is_active' => true]);

        foreach ([$trial, $free] as $shop) {
            $this->as($shop->owner);
            $this->getJson('/api/customers')->assertOk();
        }
    }

    public function test_the_super_admin_extends_from_today_when_ended_and_from_the_end_date_when_running(): void
    {
        $ended = $this->shop(['trial_ends_at' => now()->subDays(3)]);
        $running = $this->shop(['trial_ends_at' => now()->addDays(2)]);
        $this->as($this->superAdmin());

        $this->postJson("/api/shops/{$ended->id}/extend-trial", ['days' => 7])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $ended->fresh()->trial_ends_at->timestamp, 5);

        $this->postJson("/api/shops/{$running->id}/extend-trial", ['days' => 7])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(9)->timestamp, $running->fresh()->trial_ends_at->timestamp, 5);

        // and an extended shop can work again
        $this->as($ended->owner);
        $this->getJson('/api/customers')->assertOk();
    }

    public function test_extending_validates_days_and_needs_a_trial(): void
    {
        $free = $this->shop();
        $trial = $this->shop(['trial_ends_at' => now()->addDay()]);
        $this->as($this->superAdmin());

        $this->postJson("/api/shops/{$free->id}/extend-trial", ['days' => 7])->assertStatus(409);
        $this->assertNull($free->fresh()->trial_ends_at);
        foreach ([0, 366, 'x', null] as $days) {
            $this->postJson("/api/shops/{$trial->id}/extend-trial", ['days' => $days])->assertStatus(422)->assertJsonValidationErrors('days');
        }
    }

    public function test_only_the_super_admin_can_extend_a_trial(): void
    {
        $shop = $this->shop(['approved_at' => now(), 'is_active' => true, 'trial_ends_at' => now()->addDay()]);
        $this->as($shop->owner);

        $this->postJson("/api/shops/{$shop->id}/extend-trial", ['days' => 7])->assertForbidden();
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $shop->fresh()->trial_ends_at->timestamp, 5);
    }
}
