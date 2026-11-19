<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\SmsLog;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SmsBalanceLow;
use App\Services\Billing\SubscriptionService;
use App\Services\ShopService;
use App\Services\Sms\SmsService;
use App\Services\Sms\SmsTemplates;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** SMS due alerts: one SMS per due per stage (3 days before, on the day, 1 and 7 days after), checked again just before it goes. */
class SmsDueAlertTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        config(['sms.driver' => 'log', 'sms.fallback' => null]);
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00'));
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A', 'phone' => '01811111111', 'approved_at' => now()], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        app(SubscriptionService::class)->extend($this->shop, 365); // the tests move past the free trial
        $this->owner = $this->shop->owner;
        $this->as($this->owner);
    }

    private function otherShop(): Shop
    {
        $other = app(ShopService::class)->create(['name' => 'Shop B', 'approved_at' => now()], ['name' => 'Owner B', 'email' => 'owner@b.test', 'password' => 'password']);
        app(SubscriptionService::class)->extend($other, 365);

        return $other;
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    private function enable(array $sms = []): void
    {
        $this->putJson('/api/settings', ['sms' => $sms + ['enabled' => true]])->assertOk();
    }

    /** 500,000 with 200,000 down, 300,000 + 20% over 12 months from 2026-02-01: 30,000 a month. */
    private function installmentSale(string $phone = '01712345678', string $chassis = 'CH-1', string $firstDue = '2026-02-01'): array
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Seller'], ['phone' => '01000000000']);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => 'EN-'.$chassis, 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');
        $customer = Customer::create(['name' => 'Karim', 'phone' => $phone]);

        return $this->postJson('/api/sales', [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 500000,
            'payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 12, 'first_due_date' => $firstDue, 'interest_rate' => 20,
        ])->assertCreated()->json('data');
    }

    private function runAlerts(string $at): void
    {
        $this->travelTo(Carbon::parse($at));
        $this->artisan('sms:due-alerts')->assertSuccessful();
    }

    public function test_each_due_gets_one_sms_per_stage(): void
    {
        $this->installmentSale();
        $this->enable();

        $this->runAlerts('2026-01-29 10:00:00'); // 3 days before
        $this->runAlerts('2026-01-29 11:00:00'); // run again the same day: no second SMS
        $this->runAlerts('2026-02-01 10:00:00'); // on the day
        $this->runAlerts('2026-02-02 10:00:00'); // 1 day after
        $this->runAlerts('2026-02-05 10:00:00'); // nothing on this day
        $this->runAlerts('2026-02-08 10:00:00'); // 7 days after

        $logs = SmsLog::orderBy('id')->get();
        $this->assertSame(['upcoming', 'today', 'overdue', 'final'], $logs->pluck('stage')->all());
        $this->assertSame(['sent'], $logs->pluck('status')->unique()->values()->all());
        $this->assertSame('8801712345678', $logs[0]->phone);
        $this->assertSame('installment', $logs[0]->due_type);
        // Bangla by default, filled with the amount and date
        $this->assertSame('প্রিয় Karim, ৳30,000 পরিশোধের শেষ তারিখ 01-02-2026।', $logs[0]->message);
        $this->assertSame('শেষ স্মরণ: ৳30,000 বকেয়া। কল: 01811111111', $logs[3]->message);
        $this->assertSame(1, $logs[0]->parts);
        $this->assertEqualsWithDelta(0.35, $logs[0]->cost, 0.001);
    }

    public function test_due_alerts_are_off_until_the_shop_turns_them_on(): void
    {
        $this->installmentSale();

        $this->runAlerts('2026-01-29 10:00:00');

        $this->assertSame(0, SmsLog::count());
    }

    public function test_a_paid_due_gets_no_more_reminders(): void
    {
        $sale = $this->installmentSale();
        $this->enable();
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 30000, 'method' => 'cash'])->assertCreated();

        $this->runAlerts('2026-01-29 10:00:00');

        $this->assertSame(0, SmsLog::count());
    }

    public function test_the_due_is_checked_again_just_before_sending(): void
    {
        $sale = $this->installmentSale();
        $this->enable();
        Queue::fake();
        $this->runAlerts('2026-01-29 10:00:00');
        $log = SmsLog::sole();
        Queue::assertPushedOn('sms', SendSmsJob::class);

        // paid after it was queued: the job skips it
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 30000, 'method' => 'cash'])->assertCreated();
        app(SmsService::class)->deliver($log->id);

        $this->assertSame('skipped', $log->fresh()->status);
    }

    public function test_a_part_payment_is_reflected_in_the_amount_sent(): void
    {
        $sale = $this->installmentSale();
        $this->enable(['language' => 'en']);
        Queue::fake();
        $this->runAlerts('2026-01-29 10:00:00');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 10000, 'method' => 'cash'])->assertCreated();

        app(SmsService::class)->deliver(SmsLog::sole()->id);

        $this->assertSame('Dear Karim, Tk 20,000 is due on 01-02-2026.', SmsLog::sole()->message);
    }

    public function test_staff_can_stop_reminders_for_a_customer_or_a_sale(): void
    {
        $sale = $this->installmentSale();
        $this->enable();

        $this->patchJson("/api/customers/{$sale['customer_id']}/sms", ['sms_enabled' => false])->assertOk()->assertJsonPath('data.sms_enabled', false);
        $this->runAlerts('2026-01-29 10:00:00');
        $this->assertSame(0, SmsLog::count());

        $this->patchJson("/api/customers/{$sale['customer_id']}/sms", ['sms_enabled' => true])->assertOk();
        $this->patchJson("/api/sales/{$sale['id']}/sms", ['sms_enabled' => false])->assertOk();
        $this->runAlerts('2026-02-01 10:00:00');
        $this->assertSame(0, SmsLog::count());

        $this->patchJson("/api/sales/{$sale['id']}/sms", ['sms_enabled' => true])->assertOk();
        $this->runAlerts('2026-02-02 10:00:00');
        $this->assertSame(['overdue'], SmsLog::pluck('stage')->all());
    }

    public function test_the_shop_edits_templates_switches_stages_off_and_sets_the_days(): void
    {
        $this->installmentSale();
        $this->enable(['language' => 'en', 'days' => ['upcoming' => 5, 'overdue' => 2, 'final' => 10]]);

        $this->putJson('/api/sms-templates/due_upcoming', ['body_bn' => 'বাংলা', 'body_en' => '{shop}: {name}, pay Tk {amount} by {due_date}. Ref {ref}', 'is_active' => true])->assertOk();
        $this->putJson('/api/sms-templates/due_today', ['body_bn' => 'x', 'body_en' => 'x', 'is_active' => false])->assertOk();
        $this->putJson('/api/sms-templates/unknown', ['body_bn' => 'x', 'body_en' => 'x', 'is_active' => true])->assertNotFound();

        $this->runAlerts('2026-01-27 10:00:00'); // 5 days before
        $this->runAlerts('2026-02-01 10:00:00'); // the "today" stage is off
        $this->runAlerts('2026-02-03 10:00:00'); // 2 days after

        $logs = SmsLog::orderBy('id')->get();
        $this->assertSame(['upcoming', 'overdue'], $logs->pluck('stage')->all());
        $this->assertStringStartsWith('Shop A: Karim, pay Tk 30,000 by 01-02-2026. Ref #', $logs[0]->message);

        $templates = $this->getJson('/api/sms-templates')->assertOk()->json('data');
        $this->assertCount(4, $templates);
        $this->assertTrue($templates[0]['edited']);
        $this->assertFalse($templates[3]['edited']);
    }

    public function test_the_final_reminder_must_come_after_the_overdue_one(): void
    {
        $this->putJson('/api/settings', ['sms' => ['days' => ['overdue' => 5, 'final' => 3]]])->assertUnprocessable()->assertJsonValidationErrors('sms.days.final');
    }

    public function test_a_number_that_is_not_a_bangladeshi_mobile_is_logged_and_skipped(): void
    {
        $this->installmentSale('0200123456');
        $this->enable();

        $this->runAlerts('2026-01-29 10:00:00');

        $this->assertSame('skipped', SmsLog::sole()->status);
    }

    public function test_sms_wait_for_the_sending_hours(): void
    {
        $this->installmentSale();
        $this->enable();
        Queue::fake();

        $this->runAlerts('2026-01-29 21:30:00');

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->queue === 'sms' && $job->delay instanceof Carbon && $job->delay->format('Y-m-d H:i') === '2026-01-30 09:00');
    }

    public function test_numbers_are_normalized_to_the_international_form(): void
    {
        $this->assertSame('8801712345678', SmsService::normalize('01712-345678'));
        $this->assertSame('8801712345678', SmsService::normalize('+880 1712 345678'));
        $this->assertNull(SmsService::normalize('01212345678'));
        $this->assertNull(SmsService::normalize('12345'));
        $this->assertSame(1, SmsTemplates::parts(str_repeat('a', 160)));
        $this->assertSame(2, SmsTemplates::parts(str_repeat('অ', 71)));
    }

    public function test_sms_net_bd_sends_and_the_fallback_takes_over_when_it_fails(): void
    {
        config(['sms.driver' => 'smsnetbd', 'sms.fallback' => 'bulksmsbd', 'sms.drivers.smsnetbd.api_key' => 'key-1', 'sms.drivers.bulksmsbd.api_key' => 'key-2']);
        Http::fake([
            'api.sms.net.bd/*' => Http::sequence()
                ->push(['error' => 0, 'msg' => 'Request successfully submitted', 'data' => ['request_id' => 555]])
                ->push(['error' => 405, 'msg' => 'Insufficient balance']),
            'bulksmsbd.net/*' => Http::response(['response_code' => 202, 'message_id' => 'B-1', 'success_message' => 'SMS Submitted Successfully']),
        ]);
        $this->installmentSale();
        $this->installmentSale('01912345678', 'CH-2');
        $this->enable();

        $this->runAlerts('2026-01-29 10:00:00');

        $logs = SmsLog::orderBy('id')->get();
        $this->assertSame(['smsnetbd', 'bulksmsbd'], $logs->pluck('provider')->all());
        $this->assertSame(['555', 'B-1'], $logs->pluck('provider_msg_id')->all());
        Http::assertSent(fn ($r) => $r->url() === 'https://api.sms.net.bd/sendsms' && $r['api_key'] === 'key-1' && $r['to'] === '8801712345678');
    }

    public function test_an_sms_every_gateway_refused_is_failed_and_can_be_retried(): void
    {
        config(['sms.driver' => 'smsnetbd', 'sms.drivers.smsnetbd.api_key' => 'key-1']);
        Http::fake(['api.sms.net.bd/*' => Http::sequence()->push(['error' => 405, 'msg' => 'Insufficient balance'])->push(['error' => 0, 'data' => ['request_id' => 9]])]);
        $this->installmentSale();
        $this->enable();

        $this->runAlerts('2026-01-29 10:00:00');
        $log = SmsLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Insufficient balance', $log->response);

        $this->postJson("/api/sms/{$log->id}/retry")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/sms/{$log->id}/retry")->assertStatus(409);
    }

    public function test_the_log_masks_numbers_and_totals_the_month(): void
    {
        $this->installmentSale();
        $this->enable();
        $this->runAlerts('2026-01-29 10:00:00');
        $this->runAlerts('2026-02-01 10:00:00');

        $res = $this->getJson('/api/sms?month=2026-01')->assertOk();
        $res->assertJsonPath('data.0.phone', '017••••45678')->assertJsonPath('data.0.customer.name', 'Karim');
        $res->assertJsonPath('meta.summary.sent', 1)->assertJsonPath('meta.summary.parts', 1);
        $this->getJson('/api/sms?month=2026-02&stage=today')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/sms?status=bogus')->assertUnprocessable();
    }

    public function test_a_test_sms_goes_to_a_staff_number(): void
    {
        $this->postJson('/api/sms/test', ['phone' => '01712345678'])->assertCreated()->assertJsonPath('message', 'Test SMS sent');
        $this->assertSame('8801712345678', SmsLog::sole()->phone);
        $this->postJson('/api/sms/test', ['phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_settings_show_the_sms_group(): void
    {
        $this->getJson('/api/settings')->assertOk()
            ->assertJsonPath('data.sms.enabled', false)
            ->assertJsonPath('data.sms.language', 'bn')
            ->assertJsonPath('data.sms.days.upcoming', 3)
            ->assertJsonPath('data.sms.live', false);
    }

    public function test_the_balance_check_emails_the_super_admins_when_low(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'super_admin', 'shop_id' => null]);
        config(['sms.driver' => 'smsnetbd', 'sms.drivers.smsnetbd.api_key' => 'key-1', 'sms.low_balance' => 200]);
        Http::fake(['api.sms.net.bd/user/balance/*' => Http::response(['error' => 0, 'msg' => 'Success', 'data' => ['balance' => '150.50']])]);

        $this->artisan('sms:balance-check')->assertSuccessful();

        Notification::assertSentTo($admin, SmsBalanceLow::class);
    }

    public function test_sms_permissions(): void
    {
        $sale = $this->installmentSale();
        $this->enable();
        $this->runAlerts('2026-01-29 10:00:00');
        $id = SmsLog::sole()->id;

        $this->as(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $this->getJson('/api/sms')->assertForbidden();
        $this->postJson("/api/sms/{$id}/retry")->assertForbidden();
        $this->patchJson("/api/sales/{$sale['id']}/sms", ['sms_enabled' => false])->assertForbidden();
        $this->putJson('/api/sms-templates/due_today', ['body_bn' => 'x', 'body_en' => 'x', 'is_active' => true])->assertForbidden();
        $this->postJson('/api/sms/test', ['phone' => '01712345678'])->assertForbidden();

        $this->as(User::factory()->create(['role' => 'super_admin', 'shop_id' => null]));
        $this->getJson('/api/sms')->assertForbidden();
    }

    public function test_another_shop_cannot_see_or_touch_this_shops_sms(): void
    {
        $sale = $this->installmentSale();
        $this->enable();
        $this->putJson('/api/sms-templates/due_upcoming', ['body_bn' => 'A-র টেক্সট', 'body_en' => 'A text', 'is_active' => true])->assertOk();
        $this->runAlerts('2026-01-29 10:00:00');
        $log = SmsLog::sole();
        $log->update(['status' => 'failed']);

        $other = $this->otherShop();
        $this->as($other->owner);

        $this->getJson('/api/sms?month=2026-01')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/sms/{$log->id}/retry")->assertNotFound();
        $this->patchJson("/api/sales/{$sale['id']}/sms", ['sms_enabled' => false])->assertNotFound();
        $this->patchJson("/api/customers/{$sale['customer_id']}/sms", ['sms_enabled' => false])->assertNotFound();
        // shop B sees the built-in text, not shop A's edit
        $this->assertSame(SmsTemplates::DEFAULTS['due_upcoming'][0], $this->getJson('/api/sms-templates')->json('data.0.body_bn'));
        $this->assertTrue(Sale::withoutGlobalScopes()->find($sale['id'])->sms_enabled);
    }

    public function test_the_daily_run_reaches_every_shop_but_only_its_own_dues(): void
    {
        $this->installmentSale();
        $this->enable();

        $other = $this->otherShop();
        $this->as($other->owner);
        $this->installmentSale('01912345678', 'CH-B');
        // shop B keeps SMS off

        auth()->forgetGuards(); // the scheduler has no signed-in user
        $this->runAlerts('2026-01-29 10:00:00');

        $log = SmsLog::withoutGlobalScopes()->sole();
        $this->assertSame($this->shop->id, $log->shop_id);
        $this->assertSame('8801712345678', $log->phone);
        $this->assertSame(1, Installment::withoutGlobalScopes()->where('shop_id', $other->id)->whereDate('due_date', '2026-02-01')->count());
    }

    public function test_each_shop_runs_at_its_own_time_once_a_day(): void
    {
        $this->installmentSale();
        $this->enable(['send_time' => '15:00']);

        $this->runAlerts('2026-01-29 10:00:00');
        $this->assertSame(0, SmsLog::count());

        $this->runAlerts('2026-01-29 15:05:00');
        $this->assertSame(1, SmsLog::count());
        $this->getJson('/api/settings')->assertJsonPath('data.sms.last_run', '2026-01-29 15:05')->assertJsonPath('data.sms.last_count', 1);
    }

    public function test_sms_keep_to_the_shops_own_sending_hours(): void
    {
        $this->installmentSale();
        $this->enable(['send_from' => 11, 'send_until' => 14, 'send_time' => '11:00']);
        Queue::fake();

        $this->runAlerts('2026-01-29 15:00:00');

        Queue::assertPushed(SendSmsJob::class, fn (SendSmsJob $job) => $job->delay instanceof Carbon && $job->delay->format('Y-m-d H:i') === '2026-01-30 11:00');
    }

    public function test_the_send_time_must_be_within_the_sending_hours(): void
    {
        $this->putJson('/api/settings', ['sms' => ['send_time' => '21:00']])->assertUnprocessable()->assertJsonValidationErrors('sms.send_time');
        $this->putJson('/api/settings', ['sms' => ['send_from' => 12, 'send_until' => 12]])->assertUnprocessable()->assertJsonValidationErrors('sms.send_until');
        // the saved send time (10:00) falls outside 11 to 14
        $this->putJson('/api/settings', ['sms' => ['send_from' => 11, 'send_until' => 14]])->assertUnprocessable()->assertJsonValidationErrors('sms.send_time');
        $this->putJson('/api/settings', ['sms' => ['send_from' => 8, 'send_until' => 21, 'send_time' => '08:30']])->assertOk()
            ->assertJsonPath('data.sms.send_time', '08:30')->assertJsonPath('data.sms.send_until', 21);
    }

    public function test_run_now_sends_todays_alerts_at_once(): void
    {
        $this->installmentSale();
        $this->travelTo(Carbon::parse('2026-01-29 09:30:00'));
        $this->postJson('/api/sms/run')->assertStatus(409); // alerts are off

        $this->enable();
        $this->postJson('/api/sms/run')->assertOk()->assertJsonPath('message', '1 SMS queued')->assertJsonPath('data.last_count', 1);
        $this->postJson('/api/sms/run')->assertOk()->assertJsonPath('message', '0 SMS queued');
        // the scheduler does not run the shop again today
        $this->runAlerts('2026-01-29 10:00:00');
        $this->assertSame(1, SmsLog::count());

        $this->as(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $this->postJson('/api/sms/run')->assertForbidden();
    }

    public function test_an_sms_waiting_for_the_sending_hours_is_not_queued_again_on_every_tick(): void
    {
        $this->installmentSale();
        $this->enable();
        Queue::fake();

        $this->runAlerts('2026-01-29 21:30:00'); // after hours: waits for 9 am
        $this->runAlerts('2026-01-29 22:45:00');
        $this->runAlerts('2026-01-29 23:55:00');

        Queue::assertPushed(SendSmsJob::class, 1);
    }

    public function test_an_sms_another_job_is_sending_is_not_sent_twice(): void
    {
        config(['sms.driver' => 'smsnetbd', 'sms.drivers.smsnetbd.api_key' => 'key-1']);
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0, 'data' => ['request_id' => 1]])]);
        $this->installmentSale();
        $this->enable();
        Queue::fake();
        $this->runAlerts('2026-01-29 10:00:00');
        $log = SmsLog::sole();

        $lock = Cache::lock("sms-log:{$log->id}", 120);
        $this->assertTrue($lock->get()); // a first job is sending it
        app(SmsService::class)->deliver($log->id); // a second job for the same SMS
        Http::assertNothingSent();
        $this->assertSame('queued', $log->fresh()->status);

        $lock->release();
        app(SmsService::class)->deliver($log->id);
        app(SmsService::class)->deliver($log->id); // a late duplicate job finds it sent
        Http::assertSentCount(1);
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_a_run_does_not_read_more_per_due_than_its_own_insert(): void
    {
        $this->installmentSale('01712345678', 'CH-1');
        foreach (['01812345671', '01812345672', '01812345673'] as $i => $phone) {
            $this->installmentSale($phone, "CH-B{$i}", '2026-02-02');
        }
        $this->enable();
        Queue::fake();
        $sms = app(SmsService::class);
        $queries = function (string $at) use ($sms): int {
            $this->travelTo(Carbon::parse($at));
            SmsLog::query()->update(['status' => 'sent']); // nothing waiting: the stale sweep finds nothing to send again
            DB::flushQueryLog();
            DB::enableQueryLog();
            $sms->runShop($this->shop->id);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $one = $queries('2026-01-29 10:00:00'); // 3 days before 1 Feb: one due
        $three = $queries('2026-01-30 10:00:00'); // 3 days before 2 Feb: three dues

        $this->assertSame(4, SmsLog::count());
        // the sale, the customer, the settings and the texts are read once for the run, not once per due
        $this->assertLessThanOrEqual($one + 2, $three);
    }

    public function test_a_scheduler_tick_after_the_days_run_costs_two_queries(): void
    {
        $this->installmentSale();
        $this->enable();
        $this->runAlerts('2026-01-29 10:00:00');
        $this->travelTo(Carbon::parse('2026-01-29 10:05:00'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SmsService::class)->queueDueAlerts();
        DB::disableQueryLog();

        $this->assertCount(2, DB::getQueryLog());
    }
}
