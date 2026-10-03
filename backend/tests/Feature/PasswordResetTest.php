<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_is_emailed_and_the_new_password_works(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'Reset Me', 'email' => 'reset@example.com', 'password' => 'oldpassword', 'role' => 'super_admin']);
        $user->createToken('api');

        $this->postJson('/api/forgot-password', ['email' => 'reset@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token, $user) {
            $token = $n->token;
            $this->assertStringContainsString('/reset-password?token='.$token.'&email=reset%40example.com', (new ResetPassword($token))->toMail($user)->actionUrl);

            return true;
        });

        $this->postJson('/api/reset-password', ['email' => 'reset@example.com', 'token' => 'wrong', 'password' => 'newpassword', 'password_confirmation' => 'newpassword'])->assertStatus(422);
        $this->postJson('/api/reset-password', ['email' => 'reset@example.com', 'token' => $token, 'password' => 'newpassword', 'password_confirmation' => 'other'])->assertStatus(422);
        $this->postJson('/api/reset-password', ['email' => 'reset@example.com', 'token' => $token, 'password' => 'newpassword', 'password_confirmation' => 'newpassword'])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0); // signed out everywhere
        $this->postJson('/api/login', ['email' => 'reset@example.com', 'password' => 'oldpassword'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'reset@example.com', 'password' => 'newpassword'])->assertOk();
    }

    public function test_an_unknown_email_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->assertJsonPath('message', 'If that email has an account, a reset link is on its way.');
        Notification::assertNothingSent();
    }
}
