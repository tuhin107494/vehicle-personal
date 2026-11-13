<?php

namespace Tests\Feature;

use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayPayment;
use App\Models\Gateway\LedgerEntry;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\GatewayHealth;
use App\Services\Gateway\GatewayRegistry;
use App\Services\Gateway\PaymentVerifier;
use App\Services\ShopService;
use Database\Seeders\BillingSeeder;
use Database\Seeders\GatewayConfigSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeGateway;
use Tests\TestCase;

/** Phase 4 of the payment module: gateway settings, checkout, attempts, verification, the outbox and the bank-transfer flow. */
class GatewayPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private User $admin;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 10:00:00');
        $this->seed(PermissionSeeder::class);
        $this->seed(BillingSeeder::class);
        $this->seed(GatewayConfigSeeder::class);
        GatewayRegistry::register(FakeGateway::CODE, FakeGateway::class);
        FakeGateway::reset();
        GatewayConfig::create(['code' => FakeGateway::CODE, 'display_name' => 'Fake', 'sort_order' => 9]);

        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = User::where('shop_id', $this->shop->id)->firstOrFail();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'shop_id' => null, 'password' => 'admin-pass']);
        $this->invoice = app(SubscriptionService::class)->subscribe($this->shop, Plan::where('slug', 'business')->firstOrFail())['invoice'];
        Sanctum::actingAs($this->owner);
    }

    protected function tearDown(): void
    {
        GatewayRegistry::reset();
        parent::tearDown();
    }

    private function asAdmin(): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($this->admin);
    }

    private function asOwner(): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($this->owner);
    }

    /** Fills a gateway in as the super admin would, through the API. */
    private function configure(string $code, array $sandbox, array $general = []): void
    {
        $this->asAdmin();
        $this->putJson("/api/admin/gateways/$code", ['general' => ['is_active' => true] + $general, 'sandbox' => $sandbox])->assertOk();
        $this->asOwner();
    }

    private function bank(array $general = []): void
    {
        $this->configure('manual', ['bank_name' => 'City Bank', 'account_name' => 'Gari Becho Ltd', 'account_number' => '1234567890', 'instructions' => 'Write the reference on the slip.', 'proof_required' => '0'], $general);
    }

    private function fake(array $general = []): void
    {
        $this->configure('fake', ['base_url' => 'https://fake.test', 'app_secret' => 'good-secret'], $general);
    }

    private function pay(string $gateway, ?string $key = null, ?Invoice $invoice = null)
    {
        return $this->postJson('/api/billing/invoices/'.($invoice ?? $this->invoice)->id.'/pay', ['gateway' => $gateway, 'idempotency_key' => $key ?? Str::random(32)]);
    }

    public function test_secrets_are_masked_in_every_answer_and_a_blank_secret_keeps_the_saved_one(): void
    {
        $this->fake();
        $this->asAdmin();

        $show = $this->getJson('/api/admin/gateways/fake')->assertOk();
        $show->assertJsonPath('data.details.sandbox.app_secret', '••••••••cret')->assertJsonPath('data.details.sandbox.base_url', 'https://fake.test')
            ->assertJsonPath('data.details.configured.sandbox', true)->assertJsonPath('data.details.configured.live', false)
            ->assertJsonPath('data.details.schema.1.is_secret', true)->assertJsonPath('data.details.urls.webhook', config('app.url').'/api/gateways/fake/webhook');
        $this->assertStringNotContainsString('good-secret', $show->getContent());
        $this->assertStringNotContainsString('good-secret', $this->getJson('/api/admin/gateways')->getContent());

        // a blank secret on edit keeps the old one; a new value replaces it
        $this->putJson('/api/admin/gateways/fake', ['sandbox' => ['base_url' => 'https://new.test', 'app_secret' => '']])->assertOk();
        $this->assertSame(['base_url' => 'https://new.test', 'app_secret' => 'good-secret'], GatewayConfig::where('code', 'fake')->first()->sandbox_credentials);
        $this->putJson('/api/admin/gateways/fake', ['sandbox' => ['app_secret' => 'other']])->assertOk();
        $this->assertSame('other', GatewayConfig::where('code', 'fake')->first()->sandbox_credentials['app_secret']);

        // audited, without the secret's value
        $audit = DB::table('admin_audit_logs')->where('subject_type', 'gateway_configs')->get();
        $this->assertGreaterThanOrEqual(3, $audit->count());
        $this->assertStringNotContainsString('good-secret', $audit->pluck('changes')->implode(' '));
        $this->assertStringNotContainsString('other', $audit->last()->changes === null ? '' : json_encode(array_filter(json_decode($audit->last()->changes, true), fn ($c) => str_contains($c['field'], 'secret'))));

        // test connection runs the gateway's own check
        $this->postJson('/api/admin/gateways/fake/test')->assertOk()->assertJsonPath('data.ok', false);
        $this->putJson('/api/admin/gateways/fake', ['sandbox' => ['app_secret' => 'good-secret']])->assertOk();
        $this->postJson('/api/admin/gateways/fake/test')->assertOk()->assertJsonPath('data.ok', true);
    }

    public function test_going_live_needs_the_admins_password_and_is_audited(): void
    {
        $this->fake();
        $this->asAdmin();
        $this->putJson('/api/admin/gateways/fake', ['general' => ['active_mode' => 'live']])->assertJsonValidationErrors('password');
        $this->putJson('/api/admin/gateways/fake', ['general' => ['active_mode' => 'live'], 'password' => 'wrong'])->assertJsonValidationErrors('password');
        $this->assertDatabaseHas('gateway_configs', ['code' => 'fake', 'active_mode' => 'sandbox']);

        $this->putJson('/api/admin/gateways/fake', ['general' => ['active_mode' => 'live'], 'password' => 'admin-pass'])->assertOk()->assertJsonPath('data.active_mode', 'live');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'gateway.mode_changed', 'user_id' => $this->admin->id]);

        // a shop owner may not touch gateway settings at all
        $this->asOwner();
        $this->getJson('/api/admin/gateways')->assertForbidden();
        $this->putJson('/api/admin/gateways/fake', ['general' => ['is_active' => false]])->assertForbidden();
    }

    public function test_checkout_offers_only_active_configured_in_range_healthy_gateways(): void
    {
        $this->bank();
        $gateways = fn () => collect($this->getJson("/api/billing/invoices/{$this->invoice->id}/gateways")->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['manual'], $gateways()); // fake is active? no: it was never switched on, and it has no credentials

        $this->fake();
        $this->assertSame(['manual', 'fake'], $gateways());

        // out of range: the fee or the amount hides it
        GatewayConfig::where('code', 'fake')->update(['min_amount_paisa' => $this->invoice->total_paisa + 1]);
        $this->assertSame(['manual'], $gateways());
        GatewayConfig::where('code', 'fake')->update(['min_amount_paisa' => null, 'max_amount_paisa' => $this->invoice->total_paisa]);
        $this->assertSame(['manual', 'fake'], $gateways());

        // the circuit breaker hides a failing gateway, and paying through it is refused
        $health = app(GatewayHealth::class);
        for ($i = 0; $i < GatewayHealth::FAILURES_TO_OPEN; $i++) {
            $health->recordFailure('fake');
        }
        $this->assertSame(['manual'], $gateways());
        $this->pay('fake')->assertStatus(409);

        // switched off: gone at once
        GatewayConfig::where('code', 'manual')->update(['is_active' => false]);
        $this->assertSame([], $gateways());
        $this->pay('manual')->assertStatus(409);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_a_bank_transfer_runs_from_pay_to_approval_and_extends_the_subscription(): void
    {
        $this->bank();
        $key = Str::random(32);

        $res = $this->pay('manual', $key)->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.initiation.kind', 'instructions')
            ->assertJsonPath('data.initiation.details.Account number', '1234567890')->assertJsonPath('data.initiation.details.Reference to write', $key);
        $attemptId = $res->json('data.id');

        // the same click again comes back with the same attempt
        $this->pay('manual', $key)->assertCreated()->assertJsonPath('data.id', $attemptId);
        $this->assertDatabaseCount('payment_attempts', 1);

        $this->postJson("/api/billing/payments/$attemptId/reference", ['reference' => 'TRX-889'])->assertOk()->assertJsonPath('data.reference', 'TRX-889');
        $this->assertSame('trialing', app(SubscriptionService::class)->current($this->shop->id)->status); // nothing until the platform confirms
        $this->getJson('/api/investors')->assertStatus(402); // the trial is on starter: no investors yet

        $this->asAdmin();
        $this->getJson('/api/admin/manual-payments')->assertOk()->assertJsonPath('data.0.id', $attemptId)->assertJsonPath('data.0.shop.name', 'Shop A')->assertJsonPath('data.0.reference', 'TRX-889');
        $this->postJson("/api/admin/manual-payments/$attemptId/approve")->assertOk()->assertJsonPath('data.status', 'success');
        $this->postJson("/api/admin/manual-payments/$attemptId/approve")->assertStatus(409);

        // one payment, a balanced ledger, a published event, a paid invoice, a running plan
        $this->assertDatabaseCount('gateway_payments', 1);
        $this->assertDatabaseHas('gateway_payments', ['attempt_id' => $attemptId, 'gateway_code' => 'manual', 'gateway_trx_id' => 'TRX-889', 'amount_paisa' => 150000]);
        $entries = LedgerEntry::withoutGlobalScopes()->get();
        $this->assertSame((int) $entries->sum('debit_paisa'), (int) $entries->sum('credit_paisa'));
        $this->assertSame(150000, (int) $entries->where('account', LedgerEntry::ACCOUNT_REVENUE)->sum('credit_paisa'));
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'payment.succeeded', 'status' => 'published']);
        $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'paid']);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'payment.approved', 'subject_id' => $attemptId]);

        $sub = app(SubscriptionService::class)->current($this->shop->id);
        $this->assertSame(['active', 'business', '2026-12-01'], [$sub->status, $sub->plan->slug, $sub->ends_at->toDateString()]);

        $this->asOwner();
        $this->getJson('/api/investors')->assertOk(); // the entitlements were refreshed by the event
        $this->getJson("/api/billing/payments/$attemptId")->assertOk()->assertJsonPath('data.status', 'success')->assertJsonPath('data.invoice.status', 'paid');
    }

    public function test_a_rejected_transfer_tells_the_shop_why_and_leaves_the_invoice_open(): void
    {
        $this->bank();
        $id = $this->pay('manual')->json('data.id');
        $this->postJson("/api/billing/payments/$id/reference", ['reference' => 'TRX-1'])->assertOk();

        $this->asAdmin();
        $this->postJson("/api/admin/manual-payments/$id/reject", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/manual-payments/$id/reject", ['reason' => 'No such transfer at the bank'])->assertOk()->assertJsonPath('data.status', 'failed');

        $this->asOwner();
        $this->getJson("/api/billing/payments/$id")->assertOk()->assertJsonPath('data.failure_reason', 'Rejected: No such transfer at the bank');
        $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'unpaid']);
        $this->assertDatabaseCount('gateway_payments', 0);
        $this->postJson("/api/billing/payments/$id/reference", ['reference' => 'TRX-2'])->assertStatus(409); // closed

        // proof can be made compulsory
        $this->configure('manual', ['proof_required' => '1']);
        $id = $this->pay('manual')->json('data.id');
        $this->postJson("/api/billing/payments/$id/reference", ['reference' => 'TRX-3'])->assertStatus(409);
    }

    public function test_verification_moves_forward_only_refuses_tampered_amounts_and_records_one_payment(): void
    {
        $this->fake();
        $verifier = app(PaymentVerifier::class);
        $attempt = fn () => PaymentAttempt::withoutGlobalScopes()->findOrFail($this->pay('fake')->assertCreated()->assertJsonPath('data.initiation.kind', 'redirect')->json('data.id'));

        // the gateway says paid, but 1 paisa short: refused, nothing written
        $a = $attempt();
        $verifier->apply($a, VerificationResult::success('T-1', 149999));
        $this->assertStringStartsWith('Amount mismatch', $a->fresh()->failure_reason);
        $this->assertDatabaseCount('gateway_payments', 0);

        // a real success, then a late failure that must not undo it, then the same success again
        $b = $attempt();
        $verifier->apply($b, VerificationResult::success('T-2', 150000));
        $this->assertSame('success', $b->fresh()->status);
        $verifier->apply($b, VerificationResult::failed('late failure'));
        $this->assertSame('success', $b->fresh()->status);
        $verifier->apply($b, VerificationResult::success('T-2', 150000));
        $this->assertDatabaseCount('gateway_payments', 1);

        // the same gateway transaction on another attempt (a replayed webhook with a new key): one payment per transaction
        $c = $attempt();
        $verifier->apply($c, VerificationResult::success('T-2', 150000));
        $this->assertSame(['failed', 'This gateway transaction was already recorded.'], [$c->fresh()->status, $c->fresh()->failure_reason]);
        $this->assertDatabaseCount('gateway_payments', 1);

        // a cancelled answer and a pending one past the window
        $d = $attempt();
        $verifier->apply($d, VerificationResult::cancelled());
        $this->assertSame('cancelled', $d->fresh()->status);
        $e = $attempt();
        $this->travel(31)->minutes();
        $verifier->apply($e, VerificationResult::pending());
        $this->assertSame('expired', $e->fresh()->status);
    }

    public function test_a_webhook_is_checked_logged_and_verified_against_the_gateway_not_its_payload(): void
    {
        $this->fake();
        $key = Str::random(32);
        $id = $this->pay('fake', $key)->json('data.id');

        // a bad signature is refused and logged
        $this->postJson('/api/gateways/fake/webhook', ['sig' => 'bad', 'key' => $key, 'amount' => 1])->assertStatus(400);
        $this->assertDatabaseHas('gateway_logs', ['gateway_code' => 'fake', 'direction' => 'webhook', 'action' => 'invalid']);
        $this->assertSame([], FakeGateway::$verified);

        // a good one is queued (sync here) and the gateway is asked; what the webhook claimed is never used
        FakeGateway::$next = VerificationResult::success('T-9', 150000);
        $this->postJson('/api/gateways/fake/webhook', ['sig' => 'ok', 'key' => $key, 'amount' => 1, 'status' => 'FAILED'])->assertOk();
        $this->assertSame([$id], FakeGateway::$verified);
        $this->assertDatabaseHas('payment_attempts', ['id' => $id, 'status' => 'success']);
        $this->assertDatabaseHas('gateway_logs', ['gateway_code' => 'fake', 'direction' => 'webhook', 'action' => 'received', 'attempt_id' => $id]);
        $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'paid']);

        // a repeat of the same webhook changes nothing and asks the gateway nothing (the attempt is final)
        $this->postJson('/api/gateways/fake/webhook', ['sig' => 'ok', 'key' => $key])->assertOk();
        $this->assertCount(1, FakeGateway::$verified);
        $this->assertDatabaseCount('gateway_payments', 1);

        // the browser callback lands on the web app's payment page
        $this->get("/api/gateways/fake/callback?sig=ok&key=$key&result=success")->assertRedirect(config('app.frontend_url')."/billing/payments/$id?result=success");

        // an address outside the allow-list, and a gateway nobody knows
        GatewayConfig::where('code', 'fake')->update(['webhook_ips' => '10.0.0.1, 10.0.0.2']);
        $this->postJson('/api/gateways/fake/webhook', ['sig' => 'ok', 'key' => $key])->assertForbidden();
        $this->postJson('/api/gateways/nope/webhook', ['sig' => 'ok'])->assertNotFound();
    }

    public function test_a_gateway_that_cannot_start_closes_the_attempt_and_counts_against_the_breaker(): void
    {
        $this->fake();
        FakeGateway::$failInitiate = true;

        $this->pay('fake')->assertStatus(409)->assertJsonPath('message', 'The payment could not be started. Try again or choose another payment method.');
        $this->assertDatabaseHas('payment_attempts', ['status' => 'failed', 'gateway_code' => 'fake']);
        $this->assertDatabaseHas('gateway_logs', ['gateway_code' => 'fake', 'direction' => 'error', 'action' => 'initiate']);
    }

    public function test_paying_is_rate_limited_and_refused_on_a_closed_invoice(): void
    {
        $this->bank();
        $this->invoice->update(['status' => 'void']);
        $this->pay('manual')->assertStatus(409);
        $this->assertDatabaseCount('payment_attempts', 0);

        // 10 a minute for the shop: the refused call above counted as one
        $this->invoice->update(['status' => 'unpaid']);
        for ($i = 0; $i < 9; $i++) {
            $this->pay('manual')->assertCreated();
        }
        $this->pay('manual')->assertStatus(429);
    }

    public function test_only_the_owner_of_the_shop_that_owns_the_invoice_can_pay_it(): void
    {
        $this->bank();
        $id = $this->pay('manual')->json('data.id');

        auth()->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'shop_id' => $this->shop->id]));
        $this->pay('manual')->assertForbidden();
        $this->getJson("/api/billing/payments/$id")->assertForbidden();

        $other = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'B', 'email' => 'b@b.test', 'password' => 'password']);
        auth()->forgetGuards();
        Sanctum::actingAs(User::where('shop_id', $other->id)->firstOrFail());
        $this->getJson("/api/billing/invoices/{$this->invoice->id}/gateways")->assertNotFound();
        $this->pay('manual')->assertNotFound();
        $this->getJson("/api/billing/payments/$id")->assertNotFound();
        $this->postJson("/api/billing/payments/$id/reference", ['reference' => 'X'])->assertNotFound();

        // the platform admin has no shop: the shop endpoints are closed (a bound record is not even found), the admin ones open
        $this->asAdmin();
        $this->getJson("/api/billing/payments/$id")->assertNotFound();
        $this->getJson("/api/billing/invoices/{$this->invoice->id}/gateways")->assertNotFound();
        $this->getJson('/api/admin/manual-payments?status=pending')->assertOk();
        $this->assertNull(GatewayPayment::withoutGlobalScopes()->first());
    }
}
