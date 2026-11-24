<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installment;
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

    public function test_deleting_a_vehicle_removes_everything_recorded_on_it(): void
    {
        $sale = $this->installmentSale('CH-TYPO');
        $xs = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data');
        $this->pay($xs['id'], 'profit_share', 1000)->assertCreated();
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 5000])->assertCreated();
        $this->postJson("/api/purchases/{$sale['purchase_id']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $other = $this->installmentSale('CH-OTHER'); // another vehicle: left as it is

        $this->deleteJson("/api/vehicles/{$sale['vehicle_id']}")->assertOk();

        // a record of what was removed is kept: who, the vehicle's numbers, and the money that went with it
        $log = $this->getJson('/api/vehicle-deletions')->assertOk()->json('data.0');
        $this->assertSame(['CH-TYPO', 'EN-CH-TYPO', $this->owner->name], [$log['chassis_no'], $log['engine_no'], $log['user']['name']]);
        $this->assertEquals(['customer_paid' => 205000, 'supplier_paid' => 1000, 'investor_received' => 300000, 'investor_paid_back' => 1000], $log['summary']['totals']);
        $this->assertSame([$sale['id']], array_column($log['summary']['sales'], 'id'));

        $this->assertDatabaseMissing('vehicles', ['id' => $sale['vehicle_id']]);
        $this->assertDatabaseMissing('sales', ['id' => $sale['id']]);
        $this->assertDatabaseMissing('purchases', ['id' => $sale['purchase_id']]);
        $this->assertDatabaseMissing('investor_sales', ['id' => $xs['id']]);
        $this->assertSame(0, InvestorSettlement::where('investor_sale_id', $xs['id'])->count());
        $this->assertDatabaseMissing('payments', ['sale_id' => $sale['id']]);
        $this->assertDatabaseMissing('purchase_payments', ['purchase_id' => $sale['purchase_id']]);
        $this->assertDatabaseHas('sales', ['id' => $other['id']]);
        $this->assertTrue(Installment::where('plan_id', $other['plan']['id'])->exists());

        // the same numbers can be recorded again
        $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-TYPO', 'engine_no' => 'EN-CH-TYPO', 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => Supplier::first()->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated();
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

    public function test_an_investor_with_only_a_cancelled_investor_sale_cannot_be_deleted(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertOk();

        // no settlements are left, but the cancelled investor sale still names the investor
        $this->deleteJson("/api/investors/{$this->investor->id}")->assertStatus(409);
        $this->assertDatabaseHas('investors', ['id' => $this->investor->id]);
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

    public function test_the_investor_gives_a_down_payment_now_and_owes_the_rest_of_their_amount(): void
    {
        $sale = $this->installmentSale();
        // 500,000 less the customer's 200,000: the investor's 300,000 on the plan at 13%; they give 250,000 now and owe 50,000
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data');
        $this->assertEquals(300000, $x['principal']);
        $this->assertEquals(339000, $x['total_payable']); // what the shop pays the investor back
        $this->assertEquals(250000, $x['investor_paid']);
        $this->assertEquals(50000, $x['investor_owes']);
        $this->assertDatabaseHas('investor_settlements', ['investor_sale_id' => $x['id'], 'type' => 'investment', 'amount' => 250000]);

        // the customer still owes the shop as before
        $this->getJson("/api/sales/{$sale['id']}")->assertJsonPath('data.due_amount', 360000);

        // the investor gives the rest later, never more than they owe; it is cash in that day
        $give = fn (float $amount) => $this->postJson('/api/investor-settlements', [
            'investor_id' => $this->investor->id, 'investor_sale_id' => $x['id'], 'type' => 'investment', 'amount' => $amount, 'date' => '2026-01-10',
        ]);
        $give(50001)->assertStatus(409);
        $id = $give(50000)->assertCreated()->json('data.id');
        $this->getJson("/api/sales/{$sale['id']}")->assertJsonPath('data.investor_sale.investor_owes', 0);
        $this->assertEquals(50000, $this->getJson('/api/reports/daily?date=2026-01-10')->json('data.investor_received.total'));

        // once they have paid their due it cannot be cancelled; that payment can still be corrected
        $this->postJson("/api/investor-sales/{$x['id']}/cancel")->assertStatus(409);
        $this->putJson("/api/investor-settlements/{$id}", ['investor_id' => $this->investor->id, 'investor_sale_id' => $x['id'], 'type' => 'investment', 'amount' => 40000, 'date' => '2026-01-10'])->assertOk();
        $this->deleteJson("/api/investor-settlements/{$id}")->assertOk();
    }

    public function test_the_amount_on_the_plan_and_the_investor_down_payment_have_limits(): void
    {
        $sale = $this->installmentSale();
        $url = "/api/sales/{$sale['id']}/investor-sale";
        $this->postJson($url, $this->investorSaleData(['plan_amount' => 300001]))->assertStatus(409);
        // the investor's down payment goes toward the price, whatever the amount on the plan: up to the 300,000 left after the customer
        $this->postJson($url, $this->investorSaleData(['plan_amount' => 200000, 'investor_down_payment' => 300001]))->assertStatus(409);
        $this->assertDatabaseCount('investor_sales', 0);

        // a smaller amount on the plan: the shop pays back that amount with interest
        $this->postJson($url, $this->investorSaleData(['plan_amount' => 200000]))->assertCreated()
            ->assertJsonPath('data.principal', 200000)->assertJsonPath('data.total_payable', 226000)->assertJsonPath('data.investor_owes', 0);
    }

    public function test_the_reports_show_investor_sales_and_what_the_investor_owes(): void
    {
        $sale = $this->installmentSale();
        // 300,000 on the plan at 13% (339,000 back); the investor gives 250,000 now and owes 50,000
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data');
        $this->pay($x['id'], 'principal_return', 10000)->assertCreated();
        $other = $this->installmentSale('CH-2');
        $cancelled = $this->postJson("/api/sales/{$other['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $this->postJson("/api/investor-sales/{$cancelled}/cancel")->assertOk();

        // the investor sales report: listed with their sale, totals over the active ones
        $this->getJson('/api/reports/investor-sales')->assertOk()
            ->assertJsonCount(2, 'data.investor_sales')
            ->assertJsonPath('data.totals.count', 1)
            ->assertJsonPath('data.totals.cancelled', 1)
            ->assertJsonPath('data.totals.amount', 300000)
            ->assertJsonPath('data.totals.payable', 339000)
            ->assertJsonPath('data.totals.paid', 10000)
            ->assertJsonPath('data.totals.due', 329000)
            ->assertJsonPath('data.totals.investor_gave', 250000)
            ->assertJsonPath('data.totals.investor_owes', 50000)
            ->assertJsonPath('data.investor_sales.1.sale.vehicle.chassis_no', 'CH-1');
        // the sales report links each sale to its investor sale
        $this->assertSame($x['id'], collect($this->getJson('/api/reports/sales?from=2026-01-01')->json('data.sales'))->firstWhere('id', $sale['id'])['investor_sale']['id']);
        $this->getJson('/api/reports/investor-sales?status=active')->assertJsonCount(1, 'data.investor_sales');
        $this->getJson('/api/reports/investor-sales?from=2026-01-07')->assertJsonCount(0, 'data.investor_sales');

        // the investor's page shows what the investor still owes the shop
        $this->getJson("/api/investors/{$this->investor->id}/portfolio")->assertJsonPath('data.summary.investor_owes', 50000);

        // the investor's down payment is cash in on the day of the investor sale
        $this->assertEquals(250000, $this->getJson('/api/reports/daily?date=2026-01-06')->json('data.investor_received.total'));

        $moderator = User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]);
        $this->as($moderator);
        $this->getJson('/api/reports/investor-sales')->assertForbidden();
    }

    public function test_the_investor_installments_are_stored_beside_the_customer_installments(): void
    {
        $sale = $this->installmentSale();
        // 339,000 back in 12 monthly installments of 28,250 from 2026-02-05
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data');

        $this->assertDatabaseCount('installments', 24); // the customer's 12 and the investor's 12
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $x['id'], 'plan_id' => null, 'seq_no' => 1, 'due_date' => '2026-02-05', 'amount' => 28250, 'status' => 'pending']);

        // paying the investor fills their installments in order
        $this->pay($x['id'], 'principal_return', 30000)->assertCreated();
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $x['id'], 'seq_no' => 1, 'paid_amount' => 28250, 'status' => 'paid']);
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $x['id'], 'seq_no' => 2, 'paid_amount' => 1750, 'status' => 'partial']);

        // a deleted payment takes it back out
        $id = $this->pay($x['id'], 'profit_share', 1000)->assertCreated()->json('data.id');
        $this->deleteJson("/api/investor-settlements/{$id}")->assertOk();
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $x['id'], 'seq_no' => 2, 'paid_amount' => 1750]);

        // the customers' lists show only the customers' installments
        $this->assertCount(12, $this->getJson('/api/installments?per_page=100')->assertOk()->json('data'));
    }

    public function test_a_cancelled_investor_sale_cancels_its_installments(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertOk();

        $this->assertSame(12, Installment::where('investor_sale_id', $id)->where('status', 'cancelled')->count());
    }

    public function test_an_investor_sale_has_its_own_page(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data.id');

        $this->getJson("/api/investor-sales/{$id}")->assertOk()
            ->assertJsonPath('data.sale.vehicle.chassis_no', 'CH-1')
            ->assertJsonPath('data.investor.name', 'Rahim')
            ->assertJsonPath('data.investor_owes', 50000)
            ->assertJsonPath('data.sale.customer.id', $sale['customer_id']);
        $this->getJson('/api/investor-sales/999999')->assertNotFound();
    }

    public function test_an_investor_installment_cannot_be_called_about_as_a_customer_due(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData())->assertCreated()->json('data.id');
        $installment = Installment::where('investor_sale_id', $id)->firstOrFail();

        $this->postJson('/api/collections/call', ['type' => 'installment', 'id' => $installment->id])->assertNotFound();
        $this->getJson("/api/collections/call-history?type=installment&id={$installment->id}")->assertNotFound();
        $this->assertDatabaseCount('call_logs', 0);
    }

    public function test_investor_dues_are_listed_for_collection(): void
    {
        $sale = $this->installmentSale();
        $owing = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data.id');
        $paid = $this->installmentSale('CH-2');
        $this->postJson("/api/sales/{$paid['id']}/investor-sale", $this->investorSaleData())->assertCreated(); // gave it all

        $this->getJson('/api/investor-sales/dues')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $owing)
            ->assertJsonPath('data.0.investor_owes', 50000);
        $this->getJson('/api/investor-sales/dues?search=CH-1')->assertJsonCount(1, 'data');
        // the investors list shows what each still owes the shop
        $this->assertEquals(50000, collect($this->getJson('/api/investors')->json('data'))->firstWhere('id', $this->investor->id)['investor_owes']);
        $this->getJson("/api/investor-sales/dues?investor_id={$this->investor->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/investor-sales/dues?search=nobody')->assertJsonCount(0, 'data');

        // once collected it is off the list
        $this->postJson('/api/investor-settlements', ['investor_id' => $this->investor->id, 'investor_sale_id' => $owing, 'type' => 'investment', 'amount' => 50000, 'date' => '2026-01-10'])->assertCreated();
        $this->getJson('/api/investor-sales/dues')->assertJsonCount(0, 'data');

        // only for those who may manage investor money
        $this->as(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $this->getJson('/api/investor-sales/dues')->assertForbidden();
    }

    public function test_the_profit_rate_is_a_yearly_rate(): void
    {
        // 10% a year on 300,000 over 24 months: 60,000 profit (12 months would be 30,000), and the same back from an amount
        $sale = $this->installmentSale();
        $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['profit_rate' => 10, 'plan_amount' => 300000, 'installment_count' => 24]))
            ->assertCreated()->assertJsonPath('data.profit_amount', 60000)->assertJsonPath('data.profit_rate', 10)->assertJsonPath('data.total_payable', 360000);
    }

    public function test_an_investor_sale_can_be_edited_until_money_moves(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data.id');
        $edit = fn (array $extra = []) => $this->putJson("/api/investor-sales/{$id}", $extra + [
            'date' => '2026-01-07', 'sale_price' => 500000, 'down_payment' => 200000, 'investor_down_payment' => 280000, 'plan_amount' => 300000,
            'profit_rate' => 20, 'installment_count' => 6, 'frequency' => 'monthly', 'first_due_date' => '2026-02-07', 'memo_no' => 'M-2', // 20% a year over 6 months: 10%
        ]);

        // the terms change: a new schedule, a new down payment entry, what the investor owes follows
        $edit()->assertOk()
            ->assertJsonPath('data.total_payable', 330000)
            ->assertJsonPath('data.number_of_installments', 6)
            ->assertJsonPath('data.memo_no', 'M-2')
            ->assertJsonPath('data.investor_owes', 20000);
        $this->assertSame(6, Installment::where('investor_sale_id', $id)->count());
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $id, 'seq_no' => 1, 'due_date' => '2026-02-07', 'amount' => 55000]);
        $this->assertDatabaseHas('investor_settlements', ['investor_sale_id' => $id, 'type' => 'investment', 'amount' => 280000, 'date' => '2026-01-07']);

        // limits as when it was made
        $edit(['plan_amount' => 300001])->assertStatus(409);
        $edit(['investor_down_payment' => 300001])->assertStatus(409);
        $edit(['date' => '2026-01-04', 'first_due_date' => '2026-02-07'])->assertStatus(409); // before the sale

        // once the investor is paid, only the details can change
        $this->pay($id, 'principal_return', 1000)->assertCreated();
        $edit(['installment_count' => 12])->assertStatus(409);
        $edit(['memo_no' => 'M-3', 'guarantor_name' => 'Karim'])->assertOk()->assertJsonPath('data.memo_no', 'M-3')->assertJsonPath('data.number_of_installments', 6);

        // the dates can still move: the same installments a month later, with what was paid kept
        $edit(['first_due_date' => '2026-03-07'])->assertOk()->assertJsonPath('data.total_payable', 330000);
        $this->assertSame(['2026-03-07', '2026-04-07'], Installment::where('investor_sale_id', $id)->orderBy('seq_no')->take(2)->get()->map(fn ($i) => $i->due_date->toDateString())->all());
        $this->assertDatabaseHas('installments', ['investor_sale_id' => $id, 'seq_no' => 1, 'paid_amount' => 1000]);

        // a cancelled one cannot be edited, and editing needs its permission
        $this->as(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $edit(['memo_no' => 'X'])->assertForbidden();
    }

    public function test_a_profit_given_as_an_amount_does_not_block_editing_the_details(): void
    {
        $sale = $this->installmentSale();
        // 10,000 on 300,000 is stored as 3.33%, and 3.33% of 300,000 is 9,990: an edit sending the rate back must keep 10,000
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['profit_rate' => null, 'profit_amount' => 10000]))->assertCreated()->json('data');
        $this->pay($x['id'], 'principal_return', 1000)->assertCreated();

        $this->putJson("/api/investor-sales/{$x['id']}", [
            'date' => $x['date'], 'sale_price' => 500000, 'down_payment' => 200000, 'investor_down_payment' => 300000, 'plan_amount' => 300000,
            'profit_rate' => $x['profit_rate'], 'installment_count' => 12, 'frequency' => 'monthly', 'first_due_date' => '2026-02-05', 'memo_no' => 'M-9',
        ])->assertOk()->assertJsonPath('data.memo_no', 'M-9')->assertJsonPath('data.profit_amount', 10000);
    }

    public function test_the_vehicle_history_shows_its_investor_sales(): void
    {
        $sale = $this->installmentSale();
        $id = $this->postJson("/api/sales/{$sale['id']}/investor-sale", $this->investorSaleData(['investor_down_payment' => 250000]))->assertCreated()->json('data.id');

        $this->getJson("/api/vehicles/{$sale['vehicle_id']}/history")->assertOk()
            ->assertJsonCount(1, 'data.investor_sales')
            ->assertJsonPath('data.investor_sales.0.id', $id)
            ->assertJsonPath('data.investor_sales.0.investor.name', 'Rahim')
            ->assertJsonPath('data.investor_sales.0.investor_owes', 50000);
    }

    public function test_the_additional_customer_option_is_on_until_a_shop_turns_it_off(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.forms.additional_customer', true);

        $this->putJson('/api/settings', ['forms' => ['additional_customer' => false]])->assertOk()->assertJsonPath('data.forms.additional_customer', false);
        $this->putJson('/api/settings', ['forms' => ['additional_customer' => 'x']])->assertJsonValidationErrors('forms.additional_customer');
    }

    public function test_only_due_and_installment_sales_are_offered_for_an_investor_sale(): void
    {
        $installment = $this->installmentSale('CH-1');
        $due = $this->installmentSale('CH-2', ['payment_type' => 'due', 'down_payment' => 300000]);
        $this->installmentSale('CH-3', ['payment_type' => 'cash', 'down_payment' => 500000]); // cash: nothing to fund
        $funded = $this->installmentSale('CH-4');
        $this->postJson("/api/sales/{$funded['id']}/investor-sale", $this->investorSaleData())->assertCreated(); // has one already

        $ids = collect($this->getJson('/api/vehicles?status=sold&fundable=1')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$installment['vehicle_id'], $due['vehicle_id']], $ids);
    }

    public function test_an_investor_sale_with_no_plan_needs_no_schedule(): void
    {
        $sale = $this->installmentSale();
        // amount on the plan 0: the investor collects from the customer directly, so no installments, date or profit are asked for
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000,
            'plan_amount' => 0, 'investor_down_payment' => 0,
        ])->assertCreated()->json('data');

        $this->assertEquals(0, $x['principal']);
        $this->assertEquals(0, $x['total_payable']);
        $this->assertSame(0, $x['number_of_installments']);
        $this->assertSame([], $x['schedule']);
        $this->assertDatabaseMissing('installments', ['investor_sale_id' => $x['id']]);
        $this->assertDatabaseMissing('investor_settlements', ['investor_sale_id' => $x['id']]);
        // nothing to pay the investor; the price left after the customer (300,000) they still owe the shop
        $this->pay($x['id'], 'principal_return', 1)->assertStatus(409);
        $this->getJson('/api/investor-sales/dues')->assertJsonCount(1, 'data')->assertJsonPath('data.0.investor_owes', 300000);
        $this->putJson("/api/investor-sales/{$x['id']}", [
            'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000, 'plan_amount' => 0, 'investor_down_payment' => 0, 'memo_no' => 'M-1',
        ])->assertOk()->assertJsonPath('data.memo_no', 'M-1');

        // the investor's down payment has nothing to do with the plan: 250,000 down on no plan leaves 50,000 to collect
        $third = $this->installmentSale('CH-3');
        $this->postJson("/api/sales/{$third['id']}/investor-sale", [
            'investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000, 'plan_amount' => 0, 'investor_down_payment' => 250000,
        ])->assertCreated()->assertJsonPath('data.investor_owes', 50000)->assertJsonPath('data.investor_paid', 250000);

        // a plan still needs its installments
        $other = $this->installmentSale('CH-2');
        $this->postJson("/api/sales/{$other['id']}/investor-sale", ['investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000, 'plan_amount' => 1000])
            ->assertJsonValidationErrors(['installment_count', 'first_due_date']);
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
        // another shop cannot open it, list it in its reports, or call about its installments
        $this->getJson("/api/investor-sales/{$id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/reports/investor-sales?from=2026-01-01')->assertOk()->json('data.investor_sales'));
        $this->postJson("/api/investor-sales/{$id}/cancel")->assertNotFound();
        $this->postJson('/api/investor-settlements', [
            'investor_id' => $theirs->id, 'investor_sale_id' => $id, 'type' => 'principal_return', 'amount' => 1000, 'date' => today()->toDateString(),
        ])->assertJsonValidationErrors('investor_sale_id');
        $this->assertDatabaseHas('investor_sales', ['id' => $id, 'status' => 'active']);
    }
}
