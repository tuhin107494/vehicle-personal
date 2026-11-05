<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    public function test_anyone_can_read_the_landing_details_without_signing_in(): void
    {
        PlatformSettings::save(['landing' => ['support_email' => 'help@example.com', 'support_phone' => '+880 1712-345678', 'whatsapp' => '+8801712345678', 'address' => 'Mirpur, Dhaka', 'support_hours' => 'Sat to Thu, 10am to 8pm']]);

        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.signup_enabled', true)
            ->assertJsonPath('data.support_email', 'help@example.com')
            ->assertJsonPath('data.support_phone', '+880 1712-345678')
            ->assertJsonPath('data.whatsapp', '+8801712345678')
            ->assertJsonPath('data.address', 'Mirpur, Dhaka')
            ->assertJsonPath('data.support_hours', 'Sat to Thu, 10am to 8pm');
    }

    public function test_unset_details_come_back_empty(): void
    {
        $this->getJson('/api/landing')->assertOk()
            ->assertJsonPath('data.support_email', null)
            ->assertJsonPath('data.whatsapp', null);
    }

    public function test_the_landing_details_say_when_sign_up_is_closed(): void
    {
        PlatformSettings::save(['signup' => ['enabled' => false]]);

        $this->getJson('/api/landing')->assertOk()->assertJsonPath('data.signup_enabled', false);
    }

    public function test_the_landing_details_expose_no_other_settings(): void
    {
        PlatformSettings::save([
            'google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'google-secret'],
            'smtp' => ['enabled' => true, 'host' => 'smtp.secret.example', 'username' => 'mailer@example.com', 'password' => 'app-password', 'from_address' => 'mailer@example.com'],
        ]);

        $response = $this->getJson('/api/landing')->assertOk();

        $this->assertEqualsCanonicalizing(['signup_enabled', 'contact_form', 'support_email', 'support_phone', 'whatsapp', 'address', 'support_hours'], array_keys($response->json('data')));
        foreach (['google-secret', 'app-password', 'smtp.secret.example', 'client-123'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_viewing_the_landing_page_does_not_use_up_the_sign_up_limits(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->getJson('/api/landing')->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(200);
    }

    public function test_the_landing_details_are_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/landing')->assertOk();
        }

        $this->getJson('/api/landing')->assertStatus(429);
    }

    public function test_the_super_admin_saves_and_clears_the_landing_details(): void
    {
        $this->actingAs($this->superAdmin())->putJson('/api/platform-settings', ['landing' => ['support_email' => 'help@example.com', 'whatsapp' => '+8801712345678']])
            ->assertOk()
            ->assertJsonPath('data.landing.support_email', 'help@example.com')
            ->assertJsonPath('data.landing.whatsapp', '+8801712345678');

        $this->assertDatabaseHas('platform_settings', ['key' => 'landing.whatsapp', 'value' => '+8801712345678']);
        $this->getJson('/api/landing')->assertJsonPath('data.support_email', 'help@example.com');

        $this->putJson('/api/platform-settings', ['landing' => ['whatsapp' => '']])->assertOk();

        $this->assertDatabaseMissing('platform_settings', ['key' => 'landing.whatsapp']);
        $this->getJson('/api/landing')->assertJsonPath('data.whatsapp', null)->assertJsonPath('data.support_email', 'help@example.com');
    }

    public function test_saving_the_landing_details_leaves_other_settings_alone(): void
    {
        PlatformSettings::save(['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'shh']]);

        $this->actingAs($this->superAdmin())->putJson('/api/platform-settings', ['landing' => ['support_phone' => '16247']])->assertOk();

        $this->assertSame('client-123', PlatformSettings::get('google.client_id'));
        $this->assertSame('shh', PlatformSettings::get('google.client_secret'));
    }

    public function test_landing_details_are_validated(): void
    {
        $this->actingAs($this->superAdmin());

        $invalid = [
            'support_email' => 'not-an-email',
            'support_phone' => 'call me',
            'address' => str_repeat('a', 256),
            'support_hours' => str_repeat('a', 256),
        ];
        foreach ($invalid as $field => $value) {
            $this->putJson('/api/platform-settings', ['landing' => [$field => $value]])->assertStatus(422)->assertJsonValidationErrors("landing.$field");
        }

        // wa.me needs the country code and digits only, so a local number would open a dead link
        foreach (['01712345678', '+880 1712 345678', '8801712345678', 'javascript:alert(1)'] as $number) {
            $this->putJson('/api/platform-settings', ['landing' => ['whatsapp' => $number]])->assertStatus(422)->assertJsonValidationErrors('landing.whatsapp');
        }

        $this->assertDatabaseCount('platform_settings', 0);
    }

    public function test_only_the_super_admin_can_change_the_landing_details(): void
    {
        $shop = Shop::create(['name' => 'Shop']);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'shop_owner', 'shop_id' => $shop->id]);

        $this->actingAs($owner)->putJson('/api/platform-settings', ['landing' => ['support_email' => 'evil@example.com']])->assertForbidden();

        $this->assertDatabaseMissing('platform_settings', ['key' => 'landing.support_email']);
    }
}
