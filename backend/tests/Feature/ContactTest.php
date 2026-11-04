<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\ReceivesMail;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = ['name' => 'Rahim Uddin', 'email' => 'rahim@example.com', 'message' => 'I would like to know more about the app.'];

    protected function setUp(): void
    {
        parent::setUp();

        // a mailer that counts as real but keeps the messages here, and DNS answered without the network
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'array', 'mail.from.address' => 'noreply@garibecho.com']);
        $this->fakeDns(true);
        PlatformSettings::save(['landing' => ['support_email' => 'help@garibecho.com']]);
    }

    private function fakeDns(bool $found): void
    {
        $this->app->instance(ReceivesMail::class, new class($found) extends ReceivesMail
        {
            public function __construct(private bool $found) {}

            protected function hasMailServer(string $domain): bool
            {
                return $this->found;
            }
        });
    }

    /** @return array<int, Email> */
    private function sent(): array
    {
        return collect(Mail::mailer('smtp')->getSymfonyTransport()->messages())->map->getOriginalMessage()->all();
    }

    public function test_a_visitor_sends_a_message_to_the_support_email(): void
    {
        $this->postJson('/api/contact', self::VALID)->assertOk()->assertJsonPath('success', true)->assertJsonPath('message', 'Message sent');

        $this->assertCount(1, $this->sent());
        $mail = $this->sent()[0];
        $this->assertSame('help@garibecho.com', $mail->getTo()[0]->getAddress());
        $this->assertSame('Website message from Rahim Uddin', $mail->getSubject());
        $this->assertStringContainsString('rahim@example.com', $mail->getTextBody());
        $this->assertStringContainsString('I would like to know more about the app.', $mail->getTextBody());
    }

    public function test_the_visitor_is_the_reply_address_and_never_the_sender(): void
    {
        $this->postJson('/api/contact', self::VALID)->assertOk();

        $mail = $this->sent()[0];
        $this->assertSame('rahim@example.com', $mail->getReplyTo()[0]->getAddress());
        $this->assertSame('noreply@garibecho.com', $mail->getFrom()[0]->getAddress());
    }

    public function test_only_support_gets_a_mail_so_the_form_cannot_email_strangers(): void
    {
        $this->postJson('/api/contact', ['email' => 'victim@example.com'] + self::VALID)->assertOk();

        $this->assertCount(1, $this->sent());
        $this->assertSame(['help@garibecho.com'], array_map(fn ($a) => $a->getAddress(), $this->sent()[0]->getTo()));
    }

    public function test_without_a_support_email_support_is_unavailable_and_nobody_else_is_mailed(): void
    {
        PlatformSettings::save(['landing' => ['support_email' => '']]);
        User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);

        $this->postJson('/api/contact', self::VALID)->assertStatus(409)
            ->assertJsonPath('message', 'Support is unavailable for now. It will be available soon.');

        $this->assertCount(0, $this->sent());
    }

    public function test_the_landing_details_offer_the_form_only_with_a_support_email(): void
    {
        $this->getJson('/api/landing')->assertJsonPath('data.contact_form', true);

        PlatformSettings::save(['landing' => ['support_email' => '']]);

        $this->getJson('/api/landing')->assertJsonPath('data.contact_form', false)->assertJsonPath('data.support_email', null);
    }

    public function test_the_landing_details_say_whether_mail_really_leaves_the_server(): void
    {
        $this->getJson('/api/landing')->assertJsonPath('data.contact_form', true);

        config(['mail.default' => 'log']);

        $this->getJson('/api/landing')->assertJsonPath('data.contact_form', false);
    }

    public function test_a_message_is_refused_rather_than_lost_when_mail_only_goes_to_the_log(): void
    {
        config(['mail.default' => 'log']);

        $this->postJson('/api/contact', self::VALID)->assertStatus(409)->assertJsonPath('success', false);
    }

    public function test_a_mail_failure_is_logged_and_told_to_the_visitor(): void
    {
        Log::spy();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('smtp down for rahim@example.com'));

        $this->postJson('/api/contact', self::VALID)->assertStatus(409)
            ->assertJsonPath('message', 'Your message could not be sent right now. Please try again, or email us directly.');

        // the class only: the error text can hold the visitor's address, which must not reach the logs
        Log::shouldHaveReceived('error')->once()->with('Contact message could not be sent', ['error' => RuntimeException::class]);
    }

    public function test_a_filled_hidden_field_sends_nothing_but_looks_like_it_worked(): void
    {
        Log::spy();

        $this->postJson('/api/contact', ['leave_blank' => 'https://spam.example'] + self::VALID)->assertOk()->assertJsonPath('message', 'Message sent');

        $this->assertCount(0, $this->sent());
        Log::shouldHaveReceived('info')->once()->with('Contact message dropped: the hidden field was filled');
    }

    public function test_a_field_browsers_autofill_cannot_drop_a_real_message(): void
    {
        $this->postJson('/api/contact', ['website' => 'https://rahim.example'] + self::VALID)->assertOk();

        $this->assertCount(1, $this->sent());
    }

    public function test_the_fields_are_validated(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // failed tries count too, and there are more of them here than the limit allows

        $invalid = [
            'name' => ['', str_repeat('a', 101), "Rahim\r\nBcc: victim@example.com"],
            'email' => ['', 'not-an-email', str_repeat('a', 250).'@example.com'],
            'message' => ['', 'too short', str_repeat('a', 1001)],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $this->postJson('/api/contact', [$field => $value] + self::VALID)->assertStatus(422)->assertJsonValidationErrors($field);
            }
        }

        $this->assertCount(0, $this->sent());
    }

    public function test_an_email_whose_domain_takes_no_mail_is_refused(): void
    {
        $this->fakeDns(false);

        $this->postJson('/api/contact', ['email' => 'rahim@gmial.con'] + self::VALID)->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Enter an email address that can receive mail.');

        $this->assertCount(0, $this->sent());
    }

    public function test_the_form_is_limited_to_three_messages_a_minute(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/contact', self::VALID)->assertOk();
        }

        $this->postJson('/api/contact', self::VALID)->assertStatus(429);

        $this->assertCount(3, $this->sent());
    }

    public function test_the_form_is_limited_to_ten_messages_an_hour(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/contact', self::VALID)->assertOk();
            RateLimiter::clear(md5('contact'.'contact-minute|127.0.0.1'));
        }

        $this->postJson('/api/contact', self::VALID)->assertStatus(429);

        $this->assertCount(10, $this->sent());
    }

    public function test_using_the_form_does_not_use_up_the_sign_up_limits(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/contact', self::VALID)->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
    }
}
