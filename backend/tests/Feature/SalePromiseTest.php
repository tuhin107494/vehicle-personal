<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installment;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** An installment sale with a promised amount: part of the price paid by a date, outside the installments and without interest. */
class SalePromiseTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00'));
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs($this->shop->owner);
    }

    /** 600,000 with 200,000 down and 50,000 promised by 2026-03-01: 350,000 at 24% a year over 5 months (35,000) in 5 monthly installments. */
    private function sell(array $extra = []): TestResponse
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '0100'.rand(1000, 9999)]);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-'.rand(1000, 9999), 'engine_no' => 'EN-'.rand(1000, 9999), 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '0200'.rand(1000, 9999)]);

        return $this->postJson('/api/sales', $extra + [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 600000,
            'payment_type' => 'installment', 'down_payment' => 200000, 'promise_amount' => 50000, 'promised_date' => '2026-03-01',
            'installment_count' => 5, 'first_due_date' => '2026-02-05', 'interest_rate' => 24,
        ]);
    }

    public function test_the_promised_amount_is_its_own_installment_without_interest(): void
    {
        $sale = $this->sell()->assertCreated()->json('data');

        // interest only on the 350,000 in installments; the customer owes the promise and the installments
        $this->assertEquals(35000, $sale['plan']['interest_amount']);
        $this->assertEquals(435000, $sale['plan']['total_payable']); // 50,000 + 385,000
        $this->assertEquals(435000, $sale['due_amount']);
        $this->assertEquals(50000, $sale['plan']['promise_amount']);

        $rows = Installment::where('plan_id', $sale['plan']['id'])->orderBy('seq_no')->get();
        $this->assertCount(6, $rows);
        $this->assertSame([0, '2026-03-01', 50000.0], [$rows[0]->seq_no, $rows[0]->due_date->toDateString(), $rows[0]->amount]);
        $this->assertSame([1, '2026-02-05', 77000.0], [$rows[1]->seq_no, $rows[1]->due_date->toDateString(), $rows[1]->amount]);
    }

    public function test_payments_go_to_what_falls_due_first(): void
    {
        $sale = $this->sell()->assertCreated()->json('data');

        // installment 1 (5 Feb) falls due before the promise (1 Mar): it is paid first, then the promise
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 100000])->assertCreated();
        $rows = Installment::where('plan_id', $sale['plan']['id'])->get()->keyBy('seq_no');
        $this->assertSame('paid', $rows[1]->status);
        $this->assertEquals(23000, $rows[0]->paid_amount);
        $this->assertSame(0.0, (float) $rows[2]->paid_amount);
    }

    public function test_the_interest_rate_is_a_yearly_rate(): void
    {
        // 100% a year on the 350,000 in installments: 12 months is 350,000 interest, 24 months twice that
        $this->sell(['interest_rate' => 100, 'installment_count' => 12])->assertCreated()->assertJsonPath('data.plan.interest_amount', 350000);
        $this->sell(['interest_rate' => 100, 'installment_count' => 24])->assertCreated()->assertJsonPath('data.plan.interest_amount', 700000);
        $this->sell(['interest_rate' => 100, 'installment_count' => 6])->assertCreated()->assertJsonPath('data.plan.interest_amount', 175000);
        $this->sell(['interest_rate' => 1000])->assertUnprocessable()->assertJsonValidationErrors('interest_rate'); // at most 100% a year
    }

    public function test_editing_only_the_promise_redraws_the_plan(): void
    {
        $sale = $this->sell()->assertCreated()->json('data');
        $edit = fn (array $extra) => $this->putJson("/api/sales/{$sale['id']}", $extra + [
            'customer_id' => $sale['customer_id'], 'sale_date' => '2026-01-05', 'sale_price' => 600000, 'payment_type' => 'installment', 'down_payment' => 200000,
            'installment_count' => 5, 'first_due_date' => '2026-02-05', 'interest_amount' => 35000, 'promise_amount' => 50000, 'promised_date' => '2026-03-01',
        ]);

        $edit(['promise_amount' => 80000])->assertOk();
        $this->assertEquals(80000, Installment::where('plan_id', '!=', null)->where('seq_no', 0)->value('amount'));

        $edit(['promise_amount' => 80000, 'promised_date' => '2026-04-01'])->assertOk();
        $this->assertSame('2026-04-01', Installment::where('seq_no', 0)->first()->due_date->toDateString());
    }

    public function test_after_a_payment_the_schedule_can_still_move(): void
    {
        $sale = $this->sell()->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 10000])->assertCreated();

        // a later sale date and first installment: the installments move a month, the amounts and what was paid stay
        $this->putJson("/api/sales/{$sale['id']}", [
            'customer_id' => $sale['customer_id'], 'sale_date' => '2026-01-08', 'sale_price' => 600000, 'payment_type' => 'installment', 'down_payment' => 200000,
            'installment_count' => 5, 'first_due_date' => '2026-03-08', 'interest_amount' => 35000, 'promise_amount' => 50000, 'promised_date' => '2026-03-01',
        ])->assertOk()->assertJsonPath('data.plan.start_date', '2026-03-08')->assertJsonPath('data.plan.total_payable', 435000);

        $rows = Installment::where('plan_id', $sale['plan']['id'])->orderBy('seq_no')->get()->keyBy('seq_no');
        $this->assertSame('2026-03-01', $rows[0]->due_date->toDateString()); // the promise keeps its date
        $this->assertSame(['2026-03-08', '2026-04-08'], [$rows[1]->due_date->toDateString(), $rows[2]->due_date->toDateString()]);
        $this->assertEquals(10000, $rows[1]->paid_amount);
    }

    public function test_the_promised_amount_has_limits(): void
    {
        $this->sell(['promise_amount' => 400000])->assertStatus(409); // not less than the 400,000 left after the down payment
        $this->sell(['promised_date' => null])->assertStatus(409); // no date to pay it by
        $this->sell(['promise_amount' => 0, 'promised_date' => null])->assertCreated()->assertJsonPath('data.plan.total_payable', 440000); // none: as before
    }
}
