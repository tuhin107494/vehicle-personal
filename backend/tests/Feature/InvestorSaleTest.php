<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A customer sale (the customer pays the shop) with an investor sale beside it (the shop pays the investor on the investor's terms). */
class InvestorSaleTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $owner;

    private Investor $investor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00'));
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = $this->shop->owner;
        $this->as($this->owner);
        $this->investor = Investor::create(['name' => 'Rahim']);
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    /** 500,000 with 200,000 down: 300,000 financed at 20% (60,000), 12 monthly installments from 2026-02-01. */
    private function installmentSale(string $chassis = 'CH-1', array $extra = []): array
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '0100'.rand(1000, 9999)]);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => 'EN-'.$chassis, 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '0200'.rand(1000, 9999)]);

        return $this->postJson('/api/sales', $extra + [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 500000,
            'payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 12, 'first_due_date' => '2026-02-01', 'interest_rate' => 20,
        ])->assertCreated()->json('data');
    }

    /** The investor funds the 300,000 at 13% (39,000): 339,000 back over 12 months from 2026-02-05. */
    private function investorSaleData(array $extra = []): array
    {
        return $extra + [
            'investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000, 'profit_rate' => 13,
            'installment_count' => 12, 'frequency' => 'monthly', 'first_due_date' => '2026-02-05',
        ];
    }

    private function pay(int $investorSaleId, string $type, float $amount): TestResponse
    {
        return $this->postJson('/api/investor-settlements', [
            'investor_id' => $this->investor->id, 'investor_sale_id' => $investorSaleId, 'type' => $type, 'amount' => $amount, 'date' => today()->toDateString(),
        ]);
    }

    public function test_an_investor_sale_is_added_to_a_customer_sale_on_its_own_terms(): void
    {
        $sale = $this->installmentSale();

        $created = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['method' => 'bank', 'memo_no' => 'M-9', 'guarantor_name' => 'Karim', 'guarantor_phone' => '01711111111']))->assertCreated()
            ->assertJsonPath('data.principal', 300000)
            ->assertJsonPath('data.memo_no', 'M-9')
            ->assertJsonPath('data.guarantor_name', 'Karim')
            ->assertJsonPath('data.profit_amount', 39000)
            ->assertJsonPath('data.total_payable', 339000)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.sale_price', 500000)
            ->assertJsonPath('data.down_payment', 200000)
            ->json('data');

        // the customer's own plan is untouched: still 20% on the 300,000
        $this->assertDatabaseHas('installment_plans', ['sale_id' => $sale['id'], 'investor_id' => null, 'interest_amount' => 60000, 'total_payable' => 360000]);
        // the investor's money in is recorded against the investor sale
        $this->assertDatabaseHas('investor_settlements', ['investor_sale_id' => $created['id'], 'type' => 'investment', 'amount' => 300000, 'date' => '2026-01-06']);

        $shown = $this->getJson("/api/sales/{$sale['id']}")->assertOk()->json('data.investor_sale');
        $this->assertSame($created['id'], $shown['id']);
        $this->assertCount(12, $shown['schedule']);
        $this->assertSame('2026-02-05', $shown['schedule'][0]['due_date']);
        $this->assertSame('2026-03-05', $shown['schedule'][1]['due_date']);
        $this->assertEqualsWithDelta(339000, array_sum(array_column($shown['schedule'], 'amount')), 0.001);
        $this->assertEquals(28250, $shown['schedule'][0]['amount']);
    }

    public function test_an_investor_sale_can_name_an_additional_customer(): void
    {
        $sale = $this->installmentSale();
        $url = "/api/sales/{$sale['id']}/investor-sale";

        // the buyer is already on the sale, so the additional customer is someone else
        $this->postJson($url, $this->investorSaleData(['customer_id' => $sale['customer_id']]))->assertStatus(409);
        $this->postJson($url, $this->investorSaleData(['customer_id' => 999999]))->assertJsonValidationErrors('customer_id');

        $second = Customer::create(['name' => 'Co-buyer', 'phone' => '01799999999']);
        $this->postJson($url, $this->investorSaleData(['customer_id' => $second->id]))->assertCreated()
            ->assertJsonPath('data.customer.name', 'Co-buyer');
        $this->getJson("/api/sales/{$sale['id']}")->assertJsonPath('data.investor_sale.customer.id', $second->id);
    }

    public function test_the_investor_profit_can_be_given_as_an_amount(): void
    {
        $sale = $this->installmentSale();

        $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['profit_rate' => null, 'profit_amount' => 30000]))->assertCreated()
            ->assertJsonPath('data.profit_amount', 30000)
            ->assertJsonPath('data.profit_rate', 10)
            ->assertJsonPath('data.total_payable', 330000);
    }

    public function test_customer_payments_do_not_credit_the_investor_of_an_investor_sale(): void
    {
        $sale = $this->installmentSale();
        $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated();

        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 30000, 'method' => 'cash'])->assertCreated();

        $this->assertDatabaseMissing('investor_settlements', ['type' => 'principal_return']);
        $this->assertDatabaseMissing('investor_settlements', ['type' => 'profit_share']);
    }

    public function test_the_investor_is_owed_on_their_own_schedule_and_cannot_be_overpaid(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');

        // two of the investor's dates have come (Feb 5 and Mar 5), whatever the customer paid
        $this->travelTo(Carbon::parse('2026-03-10'));
        $row = collect($this->getJson("/api/investors/{$this->investor->id}/vehicle-payments")->assertOk()->json('data.rows'))->firstWhere('investor_sale_id', $id);
        $this->assertNull($row['plan_id']);
        $this->assertEquals(56500, $row['current_due']);
        $this->assertEquals(339000, $row['total_due']);
        $this->assertSame(2, $row['installments_due']);
        $this->assertSame('2026-02-05', $row['next_date']);

        // one installment paid: 25,000 principal and 3,250 profit (28,250)
        $this->pay($id, 'principal_return', 25000)->assertCreated();
        $this->pay($id, 'profit_share', 3250)->assertCreated();
        $this->assertDatabaseHas('investor_settlements', ['investor_sale_id' => $id, 'type' => 'principal_return', 'amount' => 25000]);

        $row = collect($this->getJson("/api/investors/{$this->investor->id}/vehicle-payments")->json('data.rows'))->firstWhere('investor_sale_id', $id);
        $this->assertEquals(28250, $row['current_due']);
        $this->assertEquals(310750, $row['total_due']);
        $this->assertEquals(275000, $row['principal_due']);
        $this->assertEquals(35750, $row['profit_due']);
        $this->assertSame(11, $row['installments_left']);
        $this->assertSame('2026-03-05', $row['next_date']);

        // the investor list shows the same amount owed now
        $listed = collect($this->getJson('/api/investors')->assertOk()->json('data'))->firstWhere('id', $this->investor->id);
        $this->assertEquals(28250, $listed['current_due']);

        // more profit than the 35,750 left is refused, and nothing is written
        $this->pay($id, 'profit_share', 35751)->assertStatus(409);
        $this->assertDatabaseCount('investor_settlements', 3);

        // the outstanding principal counts in the investor's summary
        $this->getJson("/api/investors/{$this->investor->id}/portfolio")->assertOk()
            ->assertJsonPath('data.summary.outstanding', 275000)
            ->assertJsonPath('data.summary.sales_count', 1)
            ->assertJsonPath('data.investor_sales.0.id', $id);
    }

    public function test_a_payment_must_be_for_the_investor_sale_of_that_investor(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $other = Investor::create(['name' => 'Karim']);

        $this->postJson('/api/investor-settlements', [
            'investor_id' => $other->id, 'investor_sale_id' => $id, 'type' => 'principal_return', 'amount' => 1000, 'date' => today()->toDateString(),
        ])->assertStatus(409);
        $this->pay($id, 'withdrawal', 1000)->assertStatus(409);
        $this->assertDatabaseCount('investor_settlements', 1);
    }

    public function test_editing_or_deleting_an_investor_sale_payment_keeps_its_checks(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $paymentId = $this->pay($id, 'profit_share', 30000)->assertCreated()->json('data.id');
        $investmentId = InvestorSettlement::where('investor_sale_id', $id)->where('type', 'investment')->value('id');
        $body = fn (array $extra) => $extra + ['investor_id' => $this->investor->id, 'type' => 'profit_share', 'amount' => 30000, 'date' => today()->toDateString()];

        // its own 30,000 does not count against it: up to the 39,000 profit is fine, more is refused
        $this->putJson("/api/investor-settlements/{$paymentId}", $body(['amount' => 39000]))->assertOk();
        $this->putJson("/api/investor-settlements/{$paymentId}", $body(['amount' => 39001]))->assertStatus(409);
        // not onto another investor, and not into something other than principal or profit
        $other = Investor::create(['name' => 'Karim']);
        $this->putJson("/api/investor-settlements/{$paymentId}", $body(['investor_id' => $other->id]))->assertStatus(409);
        $this->putJson("/api/investor-settlements/{$paymentId}", $body(['type' => 'withdrawal']))->assertStatus(409);
        $this->assertDatabaseHas('investor_settlements', ['id' => $paymentId, 'investor_id' => $this->investor->id, 'type' => 'profit_share', 'amount' => 39000]);

        // the investment of an active investor sale changes only with the investor sale
        $this->putJson("/api/investor-settlements/{$investmentId}", $body(['type' => 'investment', 'amount' => 1]))->assertStatus(409);
        $this->deleteJson("/api/investor-settlements/{$investmentId}")->assertStatus(409);
        $this->assertDatabaseHas('investor_settlements', ['id' => $investmentId, 'amount' => 300000]);

        // a payment can be deleted; another settlement cannot be moved onto the investor sale
        $this->deleteJson("/api/investor-settlements/{$paymentId}")->assertOk();
        $loose = $this->postJson('/api/investor-settlements', ['investor_id' => $this->investor->id, 'type' => 'withdrawal', 'amount' => 100, 'date' => today()->toDateString()])->assertCreated()->json('data.id');
        $this->putJson("/api/investor-settlements/{$loose}", ['investor_id' => $this->investor->id, 'investor_sale_id' => $id, 'type' => 'principal_return', 'amount' => 100, 'date' => today()->toDateString()])->assertStatus(409);
        $this->assertDatabaseHas('investor_settlements', ['id' => $loose, 'investor_sale_id' => null]);
    }

    public function test_investor_sale_rules_are_enforced(): void
    {
        $this->postJson('/api/sales/999999/investor-sale', $this->investorSaleData())->assertNotFound();

        $sale = $this->installmentSale();
        $url = "/api/sales/{$sale['id']}/investor-sale";

        $this->postJson($url, [])->assertJsonValidationErrors(['investor_id', 'date', 'sale_price', 'down_payment', 'profit_rate', 'installment_count', 'first_due_date']);
        $this->postJson($url, $this->investorSaleData(['down_payment' => 500000, 'guarantor_phone' => 'abc']))->assertJsonValidationErrors(['down_payment', 'guarantor_phone']);
        $this->postJson($url, $this->investorSaleData(['installment_count' => 121, 'first_due_date' => '2026-01-01']))->assertJsonValidationErrors(['installment_count', 'first_due_date']);

        // more than the 300,000 financed
        $this->postJson($url, $this->investorSaleData(['down_payment' => 199999]))->assertStatus(409);
        // before the sale
        $this->postJson($url, $this->investorSaleData(['date' => '2026-01-04', 'first_due_date' => '2026-02-05']))->assertStatus(409);
        $this->assertDatabaseCount('investor_sales', 0);

        $this->postJson($url, $this->investorSaleData())->assertCreated();
        // only one active investor sale per sale
        $this->postJson($url, $this->investorSaleData())->assertStatus(409);
        $this->assertDatabaseCount('investor_sales', 1);

        // a due sale can be funded: the investor puts in the 200,000 due
        $due = $this->installmentSale('CH-4', ['payment_type' => 'due', 'down_payment' => 300000]);
        $this->postJson("/api/sales/{$due['id']}/investor-sale", $this->investorSaleData(['down_payment' => 300000]))->assertCreated()
            ->assertJsonPath('data.total_payable', 226000);
        $this->postJson("/api/sales/{$due['id']}/investor-sale", $this->investorSaleData(['down_payment' => 499999]))->assertStatus(409); // already has one

        // a cash sale has nothing to fund
        $cash = $this->installmentSale('CH-2', ['payment_type' => 'cash', 'down_payment' => 500000]);
        $this->postJson("/api/sales/{$cash['id']}/investor-sale", $this->investorSaleData())->assertStatus(409);

        // a sale already financed by an investor on its own plan (the older way)
        $old = $this->installmentSale('CH-3', ['investor_id' => $this->investor->id, 'investor_share_percent' => 50]);
        $this->postJson("/api/sales/{$old['id']}/investor-sale", $this->investorSaleData())->assertStatus(409);
        $this->assertDatabaseCount('investor_sales', 2);
    }

    public function test_an_investor_sale_can_be_cancelled_only_before_the_investor_is_paid(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');

        $this->postJson("/api/investor-sales/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseMissing('investor_settlements', ['investor_sale_id' => $id]);
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertStatus(409);
        $this->assertNull($this->getJson("/api/sales/{$sale['id']}")->json('data.investor_sale'));

        // a new one can be added once the old is cancelled; after a payment it stays
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $this->pay($id, 'principal_return', 1000)->assertCreated();
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertStatus(409);
        $this->assertDatabaseHas('investor_sales', ['id' => $id, 'status' => 'active']);
    }

    public function test_the_investor_sale_stays_when_the_customer_sale_changes(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');

        // the sale cannot stop financing what the investor funded
        $this->putJson("/api/sales/{$sale['id']}", [
            'customer_id' => $sale['customer_id'], 'sale_date' => '2026-01-05', 'sale_price' => 500000, 'payment_type' => 'cash', 'down_payment' => 500000,
        ])->assertStatus(409);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'payment_type' => 'installment']);

        // a cancelled customer sale still owes the investor
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'Returned'])->assertOk();
        $this->assertDatabaseHas('investor_sales', ['id' => $id, 'status' => 'active']);
    }

    public function test_investor_sales_need_their_permissions(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $moderator = User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]);
        $this->as($moderator);

        // moderators may not cancel or pay investors by default
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertForbidden();
        $this->pay($id, 'principal_return', 1000)->assertForbidden();

        $this->as($this->owner);
        $keys = collect($this->getJson('/api/roles')->json('data'))->firstWhere('role', 'moderator')['permissions'];
        $this->putJson('/api/roles/moderator/permissions', ['permissions' => array_values(array_diff($keys, ['sales.create']))])->assertOk();
        $this->as($moderator->fresh());
        $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertForbidden();
        $this->assertDatabaseCount('investor_sales', 1);
    }

    public function test_investor_sales_of_another_shop_are_not_found(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');

        $other = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'Owner B', 'email' => 'owner@b.test', 'password' => 'password']);
        $this->as($other->owner);
        $theirs = Investor::create(['name' => 'B investor']);

        $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_id' => $theirs->id]))->assertNotFound();
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertNotFound();
        $this->postJson('/api/investor-settlements', [
            'investor_id' => $theirs->id, 'investor_sale_id' => $id, 'type' => 'principal_return', 'amount' => 1000, 'date' => today()->toDateString(),
        ])->assertJsonValidationErrors('investor_sale_id');
        $this->assertDatabaseHas('investor_sales', ['id' => $id, 'status' => 'active']);
    }
}
