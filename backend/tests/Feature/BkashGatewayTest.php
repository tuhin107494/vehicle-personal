<?php

namespace Tests\Feature;

use App\Models\Billing\Invoice;
use App\Models\Billing\Plan;
use App\Models\Gateway\GatewayConfig;
use App\Models\Gateway\GatewayLog;
use App\Models\Gateway\PaymentAttempt;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Gateway\Data\VerificationResult;
use App\Services\Gateway\Gateways\BkashGateway;
use App\Services\ShopService;
use Database\Seeders\BillingSeeder;
use Database\Seeders\GatewayConfigSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The bKash tokenized checkout class against a faked bKash: token caching, request shapes, verification rules, logging. */
class BkashGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

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
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = User::where('shop_id', $this->shop->id)->firstOrFail();
        $this->admin = User::factory()->create(['role' => 'super_admin', 'shop_id' => null]);
        $this->invoice = app(SubscriptionService::class)->subscribe($this->shop, Plan::where('slug', 'business')->firstOrFail())['invoice']; // 1,500.00
        GatewayConfig::where('code', 'bkash')->update(['is_active' => true]);
        GatewayConfig::where('code', 'bkash')->first()->update(['sandbox_credentials' => ['base_url' => self::BASE, 'username' => 'merchant', 'password' => 'pw-secret', 'app_key' => 'key-123', 'app_secret' => 'secret-xyz']]);
        Sanctum::actingAs($this->owner);
        Http::preventStrayRequests();
    }

    /** The usual happy answers; a test overrides what it needs. Each call starts from a clean HTTP factory, since fakes otherwise pile up and the first match wins. */
    private function fakeBkash(array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($overrides + [
            self::BASE.'/tokenized/checkout/token/grant' => Http::response(['statusCode' => '0000', 'id_token' => 'tok-1', 'refresh_token' => 'ref-1', 'expires_in' => 3600]),
            self::BASE.'/tokenized/checkout/create' => Http::response(['statusCode' => '0000', 'paymentID' => 'TR0011abc', 'bkashURL' => 'https://sandbox.payment.bkash.com/?paymentId=TR0011abc']),
            self::BASE.'/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Initiated', 'paymentID' => 'TR0011abc']),
            self::BASE.'/tokenized/checkout/execute' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'BKX123', 'amount' => '1500.00', 'currency' => 'BDT', 'paymentID' => 'TR0011abc']),
            self::BASE.'/tokenized/checkout/payment/refund' => Http::response(['statusCode' => '0000', 'refundTrxID' => 'RF999', 'transactionStatus' => 'Completed']),
            self::BASE.'/tokenized/checkout/general/searchTransaction' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'amount' => '1500.00', 'currency' => 'BDT']),
        ]);
    }

    private function pay(?string $key = null)
    {
        return $this->postJson("/api/billing/invoices/{$this->invoice->id}/pay", ['gateway' => 'bkash', 'idempotency_key' => $key ?? Str::random(32)]);
    }

    public function test_initiate_asks_for_a_token_once_and_sends_the_payer_to_bkash(): void
    {
        $this->fakeBkash();

        $first = $this->pay()->assertCreated()->assertJsonPath('data.initiation.kind', 'redirect')
            ->assertJsonPath('data.initiation.redirect_url', 'https://sandbox.payment.bkash.com/?paymentId=TR0011abc')->assertJsonPath('data.reference', 'TR0011abc');
        $this->pay()->assertCreated();

        // one grant for two payments: the token is cached; the create call carries the token, the app key and the amount in taka
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token/grant') && $r->header('username') === ['merchant'] && $r->header('password') === ['pw-secret'] && $r['app_key'] === 'key-123' && $r['app_secret'] === 'secret-xyz');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/create') && $r->header('Authorization') === ['tok-1'] && $r->header('X-App-Key') === ['key-123']
            && $r['amount'] === '1500.00' && $r['currency'] === 'BDT' && $r['intent'] === 'sale' && $r['mode'] === '0011' && str_ends_with($r['callbackURL'], '/api/gateways/bkash/callback') && $r['merchantInvoiceNumber'] === PaymentAttempt::withoutGlobalScopes()->findOrFail($first->json('data.id'))->idempotency_key);

        // the log keeps the calls but never the secrets or the token
        $logs = GatewayLog::where('gateway_code', 'bkash')->get();
        $this->assertTrue($logs->contains(fn ($l) => $l->direction === 'request' && $l->action === 'grant_token'));
        $this->assertTrue($logs->contains(fn ($l) => $l->direction === 'response' && $l->action === 'create' && $l->http_status === 200));
        $all = json_encode($logs->pluck('payload'));
        foreach (['secret-xyz', 'pw-secret', 'tok-1', 'key-123'] as $secret) {
            $this->assertStringNotContainsString($secret, $all, "$secret leaked into gateway_logs");
        }
    }

    public function test_the_callback_leads_to_execute_and_a_completed_answer_pays_the_invoice(): void
    {
        $this->fakeBkash();
        $id = $this->pay()->json('data.id');

        // the browser comes back from bKash: the callback names the payment, the worker queries then executes
        $this->get('/api/gateways/bkash/callback?paymentID=TR0011abc&status=success')->assertRedirect(config('app.frontend_url')."/billing/payments/$id?result=returned");

        $this->assertDatabaseHas('payment_attempts', ['id' => $id, 'status' => 'success']);
        $this->assertDatabaseHas('gateway_payments', ['attempt_id' => $id, 'gateway_code' => 'bkash', 'gateway_trx_id' => 'BKX123', 'amount_paisa' => 150000]);
        $this->assertDatabaseHas('invoices', ['id' => $this->invoice->id, 'status' => 'paid']);
        $this->assertSame('active', app(SubscriptionService::class)->current($this->shop->id)->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/status') && $r['paymentID'] === 'TR0011abc');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/execute') && $r['paymentID'] === 'TR0011abc');

        // the same callback again: the attempt is final, bKash is not asked again
        $sent = count(Http::recorded());
        $this->get('/api/gateways/bkash/callback?paymentID=TR0011abc&status=success')->assertRedirect();
        $this->assertCount($sent, Http::recorded());
        $this->assertDatabaseCount('gateway_payments', 1);

        // a callback with no paymentID is refused
        $this->postJson('/api/gateways/bkash/webhook', ['status' => 'success'])->assertStatus(400);
    }

    public function test_verify_trusts_only_completed_and_refuses_a_short_amount(): void
    {
        // bKash says Completed in the query already (a lost callback found by reconciliation): no execute needed
        $this->fakeBkash([self::BASE.'/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'BKX777', 'amount' => '1500.00', 'currency' => 'BDT'])]);
        $gateway = new BkashGateway(GatewayConfig::where('code', 'bkash')->first());
        $attempt = PaymentAttempt::withoutGlobalScopes()->findOrFail($this->pay()->json('data.id'));

        $result = $gateway->verify($attempt);
        $this->assertSame([VerificationResult::SUCCESS, 'BKX777', 150000, 'BDT'], [$result->status, $result->gatewayTrxId, $result->amountPaisa, $result->currency]);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/execute'));

        // the payer cancelled on bKash's page
        $this->fakeBkash([self::BASE.'/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Cancelled'])]);
        $this->assertSame(VerificationResult::CANCELLED, $gateway->verify($attempt)->status);

        // not approved yet: execute does not complete it, so it stays pending (and expires later through the verifier)
        $this->fakeBkash([
            self::BASE.'/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Initiated']),
            self::BASE.'/tokenized/checkout/execute' => Http::response(['statusCode' => '2062', 'statusMessage' => 'The payment has not been authorized yet']),
        ]);
        $this->assertSame(VerificationResult::PENDING, $gateway->verify($attempt)->status);

        // bKash reports a smaller amount than the invoice: the verifier (not the gateway) refuses it and writes no payment
        $this->fakeBkash([self::BASE.'/tokenized/checkout/payment/status' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'BKX1', 'amount' => '1499.99', 'currency' => 'BDT'])]);
        $this->get('/api/gateways/bkash/callback?paymentID=TR0011abc&status=success');
        $this->assertStringStartsWith('Amount mismatch', $attempt->fresh()->failure_reason);
        $this->assertDatabaseCount('gateway_payments', 0);
    }

    public function test_a_gateway_error_on_create_closes_the_attempt_and_a_refund_returns_bkashs_id(): void
    {
        $this->fakeBkash([self::BASE.'/tokenized/checkout/create' => Http::response(['statusCode' => '2054', 'statusMessage' => 'Invalid Amount'])]);
        $this->pay()->assertStatus(409);
        $this->assertDatabaseHas('payment_attempts', ['status' => 'failed', 'gateway_code' => 'bkash']);
        $this->assertStringContainsString('Invalid Amount', PaymentAttempt::withoutGlobalScopes()->first()->failure_reason);

        $this->fakeBkash();
        $id = $this->pay()->json('data.id');
        $this->get('/api/gateways/bkash/callback?paymentID=TR0011abc&status=success');
        $payment = PaymentAttempt::withoutGlobalScopes()->findOrFail($id)->payment;
        $gateway = new BkashGateway(GatewayConfig::where('code', 'bkash')->first());
        $this->assertSame('RF999', $gateway->refund($payment, 50000, 'Goodwill'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/refund') && $r['trxID'] === 'BKX123' && $r['amount'] === '500.00' && $r['paymentID'] === 'TR0011abc');
        $this->assertSame(VerificationResult::SUCCESS, $gateway->queryStatus('BKX123')->status);
    }

    public function test_test_connection_reports_bkashs_message_and_new_credentials_drop_the_cached_token(): void
    {
        $this->fakeBkash([self::BASE.'/tokenized/checkout/token/grant' => Http::response(['statusCode' => '2001', 'statusMessage' => 'Invalid App Key'])]);
        auth()->forgetGuards();
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/admin/gateways/bkash/test')->assertOk()->assertJsonPath('data.ok', false)->assertJsonPath('data.error', 'bKash did not issue a token: Invalid App Key.');

        $this->fakeBkash();
        $this->postJson('/api/admin/gateways/bkash/test')->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame('tok-1', Cache::get('gateway:bkash:token:sandbox'));

        // saving new credentials forgets the token, so the next call grants a fresh one
        $this->putJson('/api/admin/gateways/bkash', ['sandbox' => ['app_secret' => 'new-secret']])->assertOk();
        $this->assertNull(Cache::get('gateway:bkash:token:sandbox'));
        $this->getJson('/api/admin/gateways/bkash')->assertOk()->assertJsonPath('data.details.sandbox.app_secret', '••••••••cret')->assertJsonPath('data.details.configured.sandbox', true);
    }
}
