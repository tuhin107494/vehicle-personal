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
        $this->assertTrue($owner->shop->isPending());

        // the same Google account cannot make a second shop
        $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Again', 'phone' => '01800000000'])->assertStatus(422);
    }
}
