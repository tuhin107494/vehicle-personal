<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    private array $form = [
        'name' => 'Rahim', 'shop_name' => 'Rahim Motors', 'phone' => '01700000000',
        'email' => 'rahim@example.com', 'password' => 'password1', 'password_confirmation' => 'password1',
    ];

    private function idToken(array $claims): string
    {
        $part = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $part(['alg' => 'RS256']).'.'.$part($claims).'.sig';
    }

    public function test_a_new_shop_waits_for_approval_then_its_owner_signs_in(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->postJson('/api/signup', $this->form)->assertCreated();

        $shop = Shop::where('name', 'Rahim Motors')->firstOrFail();
        $this->assertFalse($shop->is_active);
        $this->assertTrue($shop->isPending());
        $this->assertSame('shop_owner', $shop->owner->role);

        $this->postJson('/api/login', ['email' => 'rahim@example.com', 'password' => 'password1'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Your shop is waiting for approval. You can sign in once it is approved.');

        // the super admin sees it as pending and switches it on
        $this->actingAs($admin)->getJson('/api/shops')->assertJsonPath('data.0.approved_at', null);
        $this->putJson("/api/shops/{$shop->id}", ['name' => 'Rahim Motors', 'is_active' => true])->assertOk();
        $this->assertNotNull($shop->fresh()->approved_at);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'rahim@example.com', 'password' => 'password1'])->assertOk();
    }

    /** Approves the shop as the super admin would, then signs out so the next request is a visitor again. */
    private function approve(Shop $shop): void
    {
        $admin = User::firstOrCreate(['email' => 'admin@example.com'], ['name' => 'Admin', 'password' => 'password', 'role' => 'super_admin']);
        $this->actingAs($admin)->putJson("/api/shops/{$shop->id}", ['name' => $shop->name, 'is_active' => true])->assertOk();
        $this->app['auth']->forgetGuards();
    }

    /** Signed in with the token from a login answer, the user works as the owner of their own shop and nothing more. */
    private function assertWorksAsOwnerOf(Shop $shop, string $token): void
    {
        $this->withToken($token)->getJson('/api/me')
            ->assertJsonPath('data.role', 'shop_owner')
            ->assertJsonPath('data.shop.id', $shop->id);
        $this->withToken($token)->getJson('/api/users')->assertOk()->assertJsonPath('data.0.email', $shop->owner->email); // shop-owner only
        $this->withToken($token)->getJson('/api/roles')->assertOk(); // shop-owner only
        $this->withToken($token)->getJson('/api/shops')->assertForbidden(); // super admin only
        $this->withToken($token)->getJson('/api/platform-settings')->assertForbidden(); // super admin only
    }

    public function test_a_password_sign_up_is_always_the_owner_of_a_new_shop_whatever_the_request_says(): void
    {
        $other = Shop::create(['name' => 'Someone else', 'is_active' => true, 'approved_at' => now()]);

        $this->postJson('/api/signup', $this->form + ['role' => 'super_admin', 'shop_id' => $other->id, 'is_active' => true])->assertCreated();

        $owner = User::where('email', 'rahim@example.com')->firstOrFail();
        $this->assertSame('shop_owner', $owner->role);
        $this->assertNotSame($other->id, $owner->shop_id);
        $this->assertSame('Rahim Motors', $owner->shop->name);
        $this->assertFalse($owner->is_active); // off until approved
        $this->assertSame(1, User::where('shop_id', $owner->shop_id)->count()); // the only user of the new shop

        $this->approve($owner->shop);
        $this->assertTrue($owner->fresh()->is_active);

        $token = $this->postJson('/api/login', ['email' => 'rahim@example.com', 'password' => 'password1'])->assertOk()->json('data.token');
        $this->assertWorksAsOwnerOf($owner->shop, $token);
    }

    public function test_a_google_sign_up_is_always_the_owner_of_a_new_shop_and_is_signed_in_at_once(): void
    {
        $other = Shop::create(['name' => 'Someone else', 'is_active' => true, 'approved_at' => now()]);
        PlatformSettings::save(['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'shh']]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['iss' => 'accounts.google.com', 'aud' => 'client-123', 'email' => 'karim@gmail.com', 'email_verified' => true, 'name' => 'Karim'])])]);

        $token = $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Karim Bikes', 'phone' => '01800000000', 'role' => 'super_admin', 'shop_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'shop_owner')
            ->json('data.token');

        $owner = User::where('email', 'karim@gmail.com')->firstOrFail();
        $this->assertSame('shop_owner', $owner->role);
        $this->assertSame('Karim Bikes', $owner->shop->name);
        $this->assertTrue($owner->is_active);
        $this->assertTrue($owner->shop->is_active);
        $this->assertFalse($owner->shop->isPending()); // no approval needed: Google proved the email

        // signed in straight from sign-up, and Google sign-in works on later visits too
        $this->assertWorksAsOwnerOf($owner->shop, $token);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertOk()->assertJsonPath('data.user.email', 'karim@gmail.com');
    }

    public function test_sign_up_rejects_a_used_email_and_can_be_closed(): void
    {
        User::create(['name' => 'Taken', 'email' => 'rahim@example.com', 'password' => 'password', 'role' => 'super_admin']);
        $this->postJson('/api/signup', $this->form)->assertStatus(422)->assertJsonValidationErrors(['email']);

        PlatformSettings::save(['signup' => ['enabled' => false]]);
        $this->postJson('/api/signup', ['email' => 'other@example.com'] + $this->form)->assertForbidden();
        $this->getJson('/api/auth/config')->assertJsonPath('data.signup_enabled', false);
    }

    public function test_sign_up_with_google_uses_the_google_name_and_email(): void
    {
        PlatformSettings::save(['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'shh']]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['iss' => 'accounts.google.com', 'aud' => 'client-123', 'email' => 'karim@gmail.com', 'email_verified' => true, 'name' => 'Karim'])])]);

        $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Karim Bikes', 'phone' => '01800000000'])->assertCreated();

        $owner = User::where('email', 'karim@gmail.com')->firstOrFail();
        $this->assertSame('Karim', $owner->name);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertFalse($owner->shop->isPending());

        // the same Google account cannot make a second shop
        $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Again', 'phone' => '01800000000'])->assertStatus(422);
    }
}
