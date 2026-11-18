<?php

namespace Tests\Feature;

use App\Models\Billing\Subscription;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\SubscriptionEndingSoon;
use App\Services\Billing\SubscriptionService;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 09:00:00');
        $this->seed(PermissionSeeder::class);
        Notification::fake();
    }

    private function shop(array $shop = []): Shop
    {
        return app(ShopService::class)->create(
            $shop + ['name' => 'Shop '.uniqid(), 'is_active' => true, 'approved_at' => now()],
            ['name' => 'Owner', 'email' => uniqid().'@t.test', 'password' => 'password'],
        );
    }

    private function sub(Shop $shop): Subscription
    {
        return app(SubscriptionService::class)->current($shop->id);
    }

    private function remind(): int
    {
        return app(SubscriptionService::class)->sendExpiryReminders();
    }

    /** @return list<int> the day counts each email to this shop's owner said */
    private function emailed(Shop $shop): array
    {
        return Notification::sent($shop->owner, SubscriptionEndingSoon::class)
            ->map(fn ($n) => (fn () => $this->days)->call($n))->values()->all();
    }

    public function test_the_owner_is_emailed_at_3_days_and_at_1_day_and_never_twice(): void
    {
        $shop = $this->shop(); // a 7-day trial: ends 25 Nov, 09:00

        $this->travelTo('2026-11-21 09:00:00'); // 4 days left
        $this->assertSame(0, $this->remind());

        $this->travelTo('2026-11-22 09:00:00'); // 3 days left
        $this->assertSame(1, $this->remind());
        $this->assertSame(0, $this->remind()); // the same day again: nothing new

        $this->travelTo('2026-11-23 09:00:00'); // 2 days left: still in the 3-day window, already sent
        $this->assertSame(0, $this->remind());

        $this->travelTo('2026-11-24 09:00:00'); // 1 day left
        $this->assertSame(1, $this->remind());

        $this->travelTo('2026-11-25 08:00:00'); // ends today: the 1-day email is already sent
        $this->assertSame(0, $this->remind());

        $this->assertSame([3, 1], $this->emailed($shop));
        $this->assertSame(1, $this->sub($shop)->last_reminder_days);
    }

    public function test_a_shop_first_seen_at_1_day_left_only_gets_the_1_day_email(): void
    {
        $shop = $this->shop();
        $this->sub($shop)->update(['ends_at' => now()->addDay()]);

        $this->remind();

        $this->assertSame([1], $this->emailed($shop));
    }

    public function test_the_email_says_when_and_what_happens_after(): void
    {
        $shop = $this->shop();
        $this->travelTo('2026-11-24 09:00:00');
        $this->remind();

        Notification::assertSentTo($shop->owner, SubscriptionEndingSoon::class, function (SubscriptionEndingSoon $n) use ($shop) {
            $mail = $n->toMail($shop->owner);
            $text = implode(' ', $mail->introLines);

            return $mail->subject === 'Your free trial ends tomorrow' && str_contains($text, '25 Nov 2026') && str_contains($text, 'read-only');
        });
    }

    public function test_extending_starts_the_reminders_again_for_the_new_end(): void
    {
        $shop = $this->shop();
        $this->travelTo('2026-11-24 09:00:00');
        $this->remind(); // the 1-day email for 25 Nov

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin']));
        $this->postJson("/api/shops/{$shop->id}/subscription/extend", ['days' => 7])->assertOk(); // now ends 2 Dec
        $this->assertNull($this->sub($shop)->last_reminder_days);

        $this->travelTo('2026-11-29 09:00:00'); // 3 days before the new end
        $this->remind();

        $this->assertSame([1, 3], $this->emailed($shop));
    }

    public function test_no_email_for_ended_far_off_disabled_or_cancelled_subscriptions(): void
    {
        $ended = $this->shop();
        $this->sub($ended)->update(['ends_at' => now()->subHour()]);
        $farOff = $this->shop();
        $this->sub($farOff)->update(['ends_at' => now()->addYears(10)]); // like a Legacy shop
        $disabled = $this->shop();
        $this->sub($disabled)->update(['ends_at' => now()->addDay()]);
        $disabled->update(['is_active' => false]);
        $cancelled = $this->shop();
        $this->sub($cancelled)->update(['ends_at' => now()->addDay(), 'status' => 'cancelled']);

        $this->assertSame(0, $this->remind());
        Notification::assertNothingSent();
    }

    public function test_the_command_runs_daily_at_9(): void
    {
        $this->shop();
        $this->travelTo('2026-11-22 09:00:00');

        $this->artisan('subscriptions:remind')->expectsOutput('Sent 1 reminder(s).')->assertSuccessful();
        $this->artisan('schedule:list')->expectsOutputToContain('subscriptions:remind');
    }
}
