<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);
    }

    private function idToken(array $claims): string
    {
        $part = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $part(['alg' => 'RS256']).'.'.$part($claims).'.sig';
    }

    private function enableGoogle(): void
    {
        PlatformSettings::save(['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'shh']]);
    }

    public function test_only_the_super_admin_manages_platform_settings(): void
    {
        $shop = Shop::create(['name' => 'Shop']);
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password', 'role' => 'shop_owner', 'shop_id' => $shop->id]);

        $this->actingAs($owner)->getJson('/api/platform-settings')->assertForbidden();
        $this->actingAs($owner)->putJson('/api/platform-settings', ['google' => ['enabled' => false]])->assertForbidden();
    }

    public function test_secrets_are_stored_encrypted_and_never_sent_back(): void
    {
        $this->actingAs($this->superAdmin())->putJson('/api/platform-settings', [
            'google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'google-secret'],
            'smtp' => ['enabled' => true, 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls', 'username' => 'me@gmail.com', 'password' => 'app-password', 'from_address' => 'me@gmail.com', 'from_name' => 'Vehicle Hub'],
        ])->assertOk()
            ->assertJsonPath('data.google.client_id', 'client-123')
            ->assertJsonPath('data.google.client_secret_set', true)
            ->assertJsonPath('data.smtp.password_set', true)
            ->assertJsonMissingPath('data.google.client_secret')
            ->assertJsonMissingPath('data.smtp.password');

        $this->assertNotEquals('google-secret', DB::table('platform_settings')->where('key', 'google.client_secret')->value('value'));

        // saving again with the secret left blank keeps it
        $this->putJson('/api/platform-settings', ['google' => ['enabled' => true, 'client_id' => 'client-456', 'client_secret' => '']])->assertOk();
        $this->assertSame('google-secret', PlatformSettings::get('google.client_secret'));
        $this->assertSame('client-456', PlatformSettings::get('google.client_id'));
    }

    public function test_turning_google_on_needs_a_client_id_and_secret(): void
    {
        $this->actingAs($this->superAdmin())->putJson('/api/platform-settings', ['google' => ['enabled' => true, 'client_id' => '', 'client_secret' => '']])
            ->assertStatus(422)->assertJsonValidationErrors(['google.client_id']);
        $this->putJson('/api/platform-settings', ['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => '']])
            ->assertStatus(422)->assertJsonValidationErrors(['google.client_secret']);
    }

    public function test_saved_smtp_settings_drive_the_mailer(): void
    {
        PlatformSettings::save(['smtp' => ['enabled' => true, 'host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'me@gmail.com', 'password' => 'app-password', 'from_address' => 'me@gmail.com', 'from_name' => 'Vehicle Hub']]);

        PlatformSettings::applyMail();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.gmail.com', config('mail.mailers.smtp.host'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('app-password', config('mail.mailers.smtp.password'));
        $this->assertSame('Vehicle Hub', config('mail.from.name'));
    }

    public function test_the_login_page_only_sees_the_google_client_id_once_it_is_on(): void
    {
        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('data.google.enabled', false)->assertJsonPath('data.google.client_id', null);

        $this->enableGoogle();

        $this->getJson('/api/auth/config')->assertOk()->assertJsonPath('data.google.enabled', true)->assertJsonPath('data.google.client_id', 'client-123')->assertJsonMissingPath('data.google.client_secret');
    }

    public function test_an_existing_user_signs_in_with_google(): void
    {
        $this->enableGoogle();
        $this->superAdmin();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['iss' => 'https://accounts.google.com', 'aud' => 'client-123', 'email' => 'admin@example.com', 'email_verified' => true])])]);

        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertOk()->assertJsonPath('data.user.email', 'admin@example.com')->assertJsonStructure(['data' => ['token']]);

        Http::assertSent(fn ($request) => $request['client_secret'] === 'shh' && $request['code'] === 'abc');
    }

    public function test_google_cannot_sign_in_unknown_or_unverified_emails_or_tokens_for_another_app(): void
    {
        $this->enableGoogle();
        $this->superAdmin();
        $answer = fn (array $claims) => Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken($claims + ['iss' => 'accounts.google.com', 'aud' => 'client-123', 'email_verified' => true])])]);

        $answer(['email' => 'stranger@example.com']);
        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertStatus(422);

        $answer(['email' => 'admin@example.com', 'email_verified' => false]);
        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertStatus(422);

        $answer(['email' => 'admin@example.com', 'aud' => 'someone-else']);
        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertStatus(422);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
