<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Notifications\ConfirmSignupEmail;
use App\Support\PlatformSettings;
use App\Support\SignupConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SignupTest extends TestCase
{
    use RefreshDatabase;

    private array $form = [
        'name' => 'Rahim', 'shop_name' => 'Rahim Motors', 'phone' => '01700000000',
        'email' => 'rahim@example.com', 'password' => 'password1', 'password_confirmation' => 'password1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function idToken(array $claims): string
    {
        $part = fn (array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');

        return $part(['alg' => 'RS256']).'.'.$part($claims).'.sig';
    }

    private function fakeGoogle(string $email, string $name = 'Karim'): void
    {
        PlatformSettings::save(['google' => ['enabled' => true, 'client_id' => 'client-123', 'client_secret' => 'shh']]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['id_token' => $this->idToken(['iss' => 'accounts.google.com', 'aud' => 'client-123', 'email' => $email, 'email_verified' => true, 'name' => $name])])]);
    }

    /** The id, expires and token from the confirmation link the user was last emailed. */
    private function linkParams(User $user): array
    {
        $url = null;
        Notification::assertSentTo($user, ConfirmSignupEmail::class, function (ConfirmSignupEmail $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith(rtrim(config('app.frontend_url'), '/').'/verify-email?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    /** Signed in with the token from a sign-in answer, the user works as the owner of their own shop and nothing more. */
    private function assertWorksAsOwnerOf(Shop $shop, string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')
            ->assertJsonPath('data.role', 'shop_owner')
            ->assertJsonPath('data.shop.id', $shop->id);
        $this->withToken($token)->getJson('/api/users')->assertOk()->assertJsonPath('data.0.email', $shop->owner->email); // shop-owner only
        $this->withToken($token)->getJson('/api/roles')->assertOk(); // shop-owner only
        $this->withToken($token)->getJson('/api/shops')->assertForbidden(); // super admin only
        $this->withToken($token)->getJson('/api/platform-settings')->assertForbidden(); // super admin only
    }

    public function test_an_email_sign_up_opens_only_from_the_link_in_the_email_which_signs_the_owner_in(): void
    {
        $this->postJson('/api/signup', $this->form)->assertCreated()->assertJsonMissingPath('data.token');

        $owner = User::where('email', 'rahim@example.com')->firstOrFail();
        $this->assertTrue($owner->shop->isPending());
        $this->assertNull($owner->email_verified_at);

        // no way in until the email is confirmed
        $this->postJson('/api/login', ['email' => 'rahim@example.com', 'password' => 'password1'])
            ->assertStatus(422)->assertJsonPath('errors.email.0', 'Confirm your email first: open the link we sent to rahim@example.com.');

        $token = $this->postJson('/api/signup/confirm', $this->linkParams($owner))
            ->assertOk()->assertJsonPath('data.user.role', 'shop_owner')->json('data.token');

        $owner->refresh();
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($owner->is_active);
        $this->assertTrue($owner->shop->is_active);
        $this->assertFalse($owner->shop->isPending());
        $this->assertWorksAsOwnerOf($owner->shop, $token);

        // and from now on the password works
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'rahim@example.com', 'password' => 'password1'])->assertOk();
    }

    public function test_the_link_works_once_and_cannot_be_changed_or_used_late(): void
    {
        $this->postJson('/api/signup', $this->form)->assertCreated();
        $owner = User::where('email', 'rahim@example.com')->firstOrFail();
        $params = $this->linkParams($owner);

        $this->postJson('/api/signup/confirm', ['token' => str_repeat('a', 64)] + $params)->assertStatus(422);
        $this->postJson('/api/signup/confirm', ['expires' => $params['expires'] + 3600] + $params)->assertStatus(422); // a longer life is not signed
        $this->postJson('/api/signup/confirm', ['id' => $owner->id + 1] + $params)->assertStatus(422);

        $this->travel(SignupConfirmation::HOURS + 1)->hours();
        $this->postJson('/api/signup/confirm', $params)->assertStatus(422);
        $this->travelBack();

        $this->postJson('/api/signup/confirm', $params)->assertOk();
        $this->postJson('/api/signup/confirm', $params)->assertStatus(422); // used
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_an_email_sign_up_is_always_the_owner_of_a_new_shop_whatever_the_request_says(): void
    {
        $other = Shop::create(['name' => 'Someone else', 'is_active' => true, 'approved_at' => now()]);

        $this->postJson('/api/signup', $this->form + ['role' => 'super_admin', 'shop_id' => $other->id, 'is_active' => true, 'email_verified_at' => now()])->assertCreated();

        $owner = User::where('email', 'rahim@example.com')->firstOrFail();
        $this->assertSame('shop_owner', $owner->role);
        $this->assertNotSame($other->id, $owner->shop_id);
        $this->assertSame('Rahim Motors', $owner->shop->name);
        $this->assertFalse($owner->is_active);
        $this->assertNull($owner->email_verified_at);
        $this->assertSame(1, User::where('shop_id', $owner->shop_id)->count());
    }

    public function test_a_new_link_can_be_asked_for_and_the_answer_never_reveals_accounts(): void
    {
        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);
        $this->postJson('/api/signup', $this->form)->assertCreated();
        $owner = User::where('email', 'rahim@example.com')->firstOrFail();

        $same = 'If %s is waiting for confirmation, a new link is on its way.';
        $this->postJson('/api/signup/resend', ['email' => 'rahim@example.com'])->assertOk()->assertJsonPath('message', sprintf($same, 'rahim@example.com'));
        Notification::assertSentToTimes($owner, ConfirmSignupEmail::class, 2);

        // a confirmed account and an unknown email get the same answer, and nothing is sent
        $this->postJson('/api/signup/resend', ['email' => 'admin@example.com'])->assertOk()->assertJsonPath('message', sprintf($same, 'admin@example.com'));
        $this->postJson('/api/signup/resend', ['email' => 'nobody@example.com'])->assertOk();
        Notification::assertCount(2);

        // signing up again with the waiting email says so, instead of "sign in"
        $this->postJson('/api/signup', $this->form)->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'This email is waiting for confirmation. Check your inbox, or ask for a new link.');
    }

    public function test_sign_ups_never_confirmed_are_deleted_after_a_week(): void
    {
        $this->postJson('/api/signup', $this->form)->assertCreated();
        $this->postJson('/api/signup', ['email' => 'fresh@example.com'] + $this->form)->assertCreated();
        $old = User::where('email', 'rahim@example.com')->firstOrFail();
        $old->shop->forceFill(['created_at' => now()->subDays(SignupConfirmation::PRUNE_AFTER_DAYS + 1)])->save();
        $kept = Shop::create(['name' => 'Real shop', 'is_active' => true, 'approved_at' => now()->subYear()]);
        $kept->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('signups:prune')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'rahim@example.com']);
        $this->assertDatabaseMissing('shops', ['id' => $old->shop_id]);
        $this->assertDatabaseHas('users', ['email' => 'fresh@example.com']); // still inside its week
        $this->assertDatabaseHas('shops', ['id' => $kept->id]);
    }

    public function test_a_google_sign_up_is_always_the_owner_of_a_new_shop_and_is_signed_in_at_once(): void
    {
        $other = Shop::create(['name' => 'Someone else', 'is_active' => true, 'approved_at' => now()]);
        $this->fakeGoogle('karim@gmail.com');

        $token = $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Karim Bikes', 'phone' => '01800000000', 'role' => 'super_admin', 'shop_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'shop_owner')
            ->json('data.token');

        $owner = User::where('email', 'karim@gmail.com')->firstOrFail();
        $this->assertSame('Karim', $owner->name);
        $this->assertSame('Karim Bikes', $owner->shop->name);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue($owner->is_active);
        $this->assertFalse($owner->shop->isPending()); // no email link needed: Google proved the email
        Notification::assertNothingSentTo($owner);

        $this->assertWorksAsOwnerOf($owner->shop, $token);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/google', ['code' => 'abc'])->assertOk()->assertJsonPath('data.user.email', 'karim@gmail.com');

        // the same Google account cannot make a second shop
        $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Again', 'phone' => '01800000000'])->assertStatus(422);
    }

    public function test_google_takes_back_an_email_someone_else_signed_up_with_but_never_confirmed(): void
    {
        // a stranger signs up with Karim's email and a password Karim does not know
        $this->postJson('/api/signup', ['email' => 'karim@gmail.com', 'shop_name' => 'Fake shop'] + $this->form)->assertCreated();
        $fakeShopId = User::where('email', 'karim@gmail.com')->value('shop_id');

        $this->fakeGoogle('karim@gmail.com');
        $this->postJson('/api/signup/google', ['code' => 'abc', 'shop_name' => 'Karim Bikes', 'phone' => '01800000000'])->assertCreated();

        $this->assertDatabaseMissing('shops', ['id' => $fakeShopId]);
        $this->assertSame('Karim Bikes', User::where('email', 'karim@gmail.com')->sole()->shop->name);
        $this->postJson('/api/login', ['email' => 'karim@gmail.com', 'password' => 'password1'])->assertStatus(422); // the stranger's password is gone
    }

    public function test_sign_up_rejects_a_used_email_and_can_be_closed(): void
    {
        User::create(['name' => 'Taken', 'email' => 'rahim@example.com', 'password' => 'password', 'role' => 'super_admin']);
        $this->postJson('/api/signup', $this->form)->assertStatus(422)->assertJsonPath('errors.email.0', 'This email already has an account. Sign in instead.');

        PlatformSettings::save(['signup' => ['enabled' => false]]);
        $this->postJson('/api/signup', ['email' => 'other@example.com'] + $this->form)->assertForbidden();
        $this->getJson('/api/auth/config')->assertJsonPath('data.signup_enabled', false);
    }
}
