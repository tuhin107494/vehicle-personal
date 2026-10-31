<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->admin = User::factory()->create(['role' => 'admin', 'shop_id' => $this->shop->id]);
        Sanctum::actingAs($this->admin);
    }

    private function stockVehicle(string $chassis = 'CH-1'): Vehicle
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '0100'.rand(1000, 9999)]);

        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => 'EN-'.$chassis, 'brand' => 'Toyota', 'model' => 'Axio',
            'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 800000,
        ])->assertCreated()->json('data.vehicle_id');

        return Vehicle::findOrFail($id);
    }

    private function buyer(): Customer
    {
        return Customer::create(['name' => 'Buyer', 'phone' => '0200'.rand(1000, 9999)]);
    }

    private function saleData(Vehicle $v, array $extra = []): array
    {
        return $extra + [
            'vehicle_id' => $v->id, 'customer_id' => $this->buyer()->id, 'sale_date' => today()->subDay()->toDateString(),
            'sale_price' => 1000000, 'payment_type' => 'cash', 'down_payment' => 1000000,
        ];
    }

    public function test_a_sale_keeps_an_optional_memo_number_and_guarantor_name(): void
    {
        $v = $this->stockVehicle('MEMO-1');
        $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => today()->addDays(10)->toDateString(), 'memo_no' => 'M-12', 'guarantor_name' => 'Karim', 'guarantor_phone' => '01711111111']))
            ->assertCreated()->assertJsonPath('data.memo_no', 'M-12')->assertJsonPath('data.guarantor_name', 'Karim');

        $v2 = $this->stockVehicle('MEMO-2');
        $this->postJson('/api/sales', $this->saleData($v2))->assertCreated()->assertJsonPath('data.memo_no', null)->assertJsonPath('data.guarantor_name', null);
    }

    private function editBody(array $sale, array $extra = []): array
    {
        return $extra + [
            'customer_id' => $sale['customer_id'], 'sale_date' => $sale['sale_date'], 'sale_price' => $sale['sale_price'],
            'payment_type' => $sale['payment_type'], 'down_payment' => $sale['down_payment'],
        ];
    }

    public function test_a_sale_can_be_edited_and_the_edit_is_kept_in_its_history(): void
    {
        $v = $this->stockVehicle('EDIT-1');
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => today()->addDays(10)->toDateString()]))->assertCreated()->json('data');

        // details only: nothing about the money changes
        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['memo_no' => 'M-5', 'guarantor_name' => 'Karim', 'promised_date' => today()->addDays(10)->toDateString()]))
            ->assertOk()->assertJsonPath('data.memo_no', 'M-5')->assertJsonPath('data.edits.0.changes.0.field', 'Memo no.');

        // no change, no history row
        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['memo_no' => 'M-5', 'guarantor_name' => 'Karim', 'promised_date' => today()->addDays(10)->toDateString()]))
            ->assertOk()->assertJsonCount(1, 'data.edits');

        // the money terms, before anything is collected: payments and the due are rebuilt
        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['sale_price' => 1200000, 'down_payment' => 500000, 'memo_no' => 'M-5', 'guarantor_name' => 'Karim', 'promised_date' => today()->addDays(10)->toDateString()]))
            ->assertOk()->assertJsonPath('data.due_amount', 700000)->assertJsonPath('data.total_paid', 500000)->assertJsonCount(2, 'data.edits');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(700000.0, (float) \App\Models\DuePromise::where('sale_id', $sale['id'])->value('promised_amount'));
    }

    public function test_money_terms_are_locked_once_a_payment_is_collected_but_details_still_edit(): void
    {
        $v = $this->stockVehicle('EDIT-2');
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000]))->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 100000, 'method' => 'cash'])->assertCreated();

        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['sale_price' => 1100000]))->assertStatus(409);
        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['memo_no' => 'M-9']))->assertOk()->assertJsonPath('data.memo_no', 'M-9');
    }

    public function test_an_installment_plan_is_rebuilt_when_its_terms_are_edited(): void
    {
        $v = $this->stockVehicle('EDIT-4');
        $plan = ['installment_count' => 6, 'first_due_date' => today()->addMonth()->toDateString(), 'interest_rate' => 10];
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'installment', 'down_payment' => 400000] + $plan))->assertCreated()->json('data');
        $this->assertDatabaseCount('installments', 6);

        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['installment_count' => 4] + $plan))->assertOk()
            ->assertJsonPath('data.plan.number_of_installments', 4)->assertJsonPath('data.edits.0.changes.0.field', 'Installments');
        $this->assertDatabaseCount('installments', 4);
        $this->assertDatabaseCount('installment_plans', 1);
    }

    public function test_a_collected_payment_can_be_edited_with_a_history_but_the_amount_stays(): void
    {
        $v = $this->stockVehicle('TX-1');
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000]))->assertCreated()->json('data');
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $sale['customer_id'], 'amount' => 100000, 'method' => 'cash'])->assertCreated();

        $rows = $this->getJson("/api/reports/person-transactions?side=customer&customer_id={$sale['customer_id']}")->assertOk()->json('data.rows');
        $payments = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'payment'));
        $collected = collect($payments)->firstWhere('paid', 100000);
        $this->assertNotNull($collected['tx']);
        $atSale = collect($payments)->firstWhere('paid', 400000);
        $this->assertNotNull($atSale['tx']); // taken at the sale: its details can be edited too

        $tx = $collected['tx'];
        $this->putJson("/api/transactions/{$tx['type']}/{$tx['id']}", ['paid_at' => today()->toDateString(), 'method' => 'bank', 'reference' => 'R-1', 'memo_no' => 'M-3', 'note' => 'fixed'])
            ->assertOk()->assertJsonPath('data.values.method', 'bank')->assertJsonPath('data.values.memo_no', 'M-3')->assertJsonCount(1, 'data.edits');
        $this->assertDatabaseHas('payments', ['id' => $tx['id'], 'amount' => 100000, 'method' => 'bank', 'memo_no' => 'M-3']);

        // the date cannot go before the sale, and a tx that cannot be edited is refused
        $this->putJson("/api/transactions/{$tx['type']}/{$tx['id']}", ['paid_at' => today()->subDays(30)->toDateString(), 'method' => 'cash'])->assertStatus(409);
        $adjustment = \App\Models\Payment::create(['sale_id' => $sale['id'], 'amount' => 1000, 'method' => 'adjustment', 'paid_at' => now(), 'received_by' => auth()->id() ?? 1, 'note' => 'Set off']);
        $this->putJson("/api/transactions/customer_payment/{$adjustment->id}", ['paid_at' => today()->toDateString(), 'method' => 'cash'])->assertStatus(409);
        $this->getJson("/api/transactions/{$tx['type']}/{$tx['id']}")->assertOk()->assertJsonPath('data.edits.0.changes.0.field', 'Method');
    }

    public function test_a_supplier_payment_and_advance_can_be_edited(): void
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01700077777']);
        $this->postJson('/api/purchases', ['vehicle_type' => 'motorcycle', 'chassis_no' => 'TX-S1', 'engine_no' => 'E-TX-S1', 'supplier_id' => $seller->id, 'purchase_date' => today()->subDays(5)->toDateString(), 'price' => 100000, 'paid_amount' => 0])->assertCreated();
        $this->postJson('/api/accounts', ['side' => 'supplier', 'supplier_id' => $seller->id, 'amount' => 130000, 'method' => 'cash'])->assertCreated(); // 100,000 for the purchase, 30,000 advance

        $rows = collect($this->getJson("/api/reports/person-transactions?side=supplier&supplier_id={$seller->id}")->assertOk()->json('data.rows'))->where('kind', '!=', 'purchase');
        $payment = $rows->firstWhere('kind', 'payment')['tx'];
        $advance = $rows->firstWhere('kind', 'advance')['tx'];
        $this->assertSame('supplier_payment', $payment['type']);
        $this->assertSame('supplier_advance', $advance['type']);

        $this->putJson("/api/transactions/{$payment['type']}/{$payment['id']}", ['paid_at' => today()->toDateString(), 'method' => 'mobile', 'reference' => 'bKash 1'])->assertOk();
        $this->putJson("/api/transactions/{$advance['type']}/{$advance['id']}", ['paid_at' => today()->toDateString(), 'method' => 'bank', 'note' => 'ahead'])->assertOk();
        $this->assertDatabaseHas('purchase_payments', ['id' => $payment['id'], 'method' => 'mobile', 'reference' => 'bKash 1', 'amount' => 100000]);
        $this->assertDatabaseHas('advances', ['id' => $advance['id'], 'method' => 'bank', 'amount' => 30000]);
    }

    public function test_a_collected_amount_can_be_changed_and_the_sale_follows(): void
    {
        $v = $this->stockVehicle('AMT-1');
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000]))->assertCreated()->json('data');
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $sale['customer_id'], 'amount' => 100000, 'method' => 'cash'])->assertCreated();
        $pay = \App\Models\Payment::where('sale_id', $sale['id'])->whereNull('note')->first();

        $this->putJson("/api/transactions/customer_payment/{$pay->id}", ['amount' => 150000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])
            ->assertOk()->assertJsonPath('data.edits.0.changes.0.field', 'Amount');
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'total_paid' => 550000, 'due_amount' => 450000]);

        // more than the sale total is refused; the down payment's amount is changed with the sale
        $this->putJson("/api/transactions/customer_payment/{$pay->id}", ['amount' => 700000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertStatus(409);
        $down = \App\Models\Payment::where('sale_id', $sale['id'])->where('note', 'Down payment')->first();
        $this->putJson("/api/transactions/customer_payment/{$down->id}", ['amount' => 450000, 'paid_at' => today()->toDateString(), 'method' => 'cash', 'note' => 'changed'])->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $down->id, 'note' => 'Down payment', 'amount' => 400000]);
        $this->getJson("/api/transactions/customer_payment/{$down->id}")->assertOk()->assertJsonPath('data.values.amount_lock', 'This was taken at the sale: change it with Edit sale.');
    }

    public function test_an_installment_amount_is_kept_within_its_installment(): void
    {
        $v = $this->stockVehicle('AMT-2');
        $plan = ['installment_count' => 4, 'first_due_date' => today()->addMonth()->toDateString(), 'interest_rate' => 0];
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'installment', 'down_payment' => 400000] + $plan))->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 100000, 'method' => 'cash'])->assertCreated();
        $pay = \App\Models\Payment::where('sale_id', $sale['id'])->whereNotNull('installment_id')->first();

        $this->putJson("/api/transactions/customer_payment/{$pay->id}", ['amount' => 120000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertOk();
        $this->assertDatabaseHas('installments', ['id' => $pay->installment_id, 'paid_amount' => 120000]);
        $this->putJson("/api/transactions/customer_payment/{$pay->id}", ['amount' => 200000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertStatus(409); // installments are 150,000
    }

    public function test_a_supplier_amount_and_advance_amount_keep_the_totals_right(): void
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01700066666']);
        $p = $this->postJson('/api/purchases', ['vehicle_type' => 'motorcycle', 'chassis_no' => 'AMT-S1', 'engine_no' => 'E-AMT-S1', 'supplier_id' => $seller->id, 'purchase_date' => today()->subDays(5)->toDateString(), 'price' => 100000, 'paid_amount' => 0])->assertCreated()->json('data');
        $this->postJson('/api/accounts', ['side' => 'supplier', 'supplier_id' => $seller->id, 'amount' => 60000, 'method' => 'cash'])->assertCreated();
        $pay = \App\Models\PurchasePayment::where('purchase_id', $p['id'])->first();

        $this->putJson("/api/transactions/supplier_payment/{$pay->id}", ['amount' => 80000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertOk();
        $this->assertDatabaseHas('purchases', ['id' => $p['id'], 'paid_amount' => 80000, 'payment_status' => 'partial']);
        $this->putJson("/api/transactions/supplier_payment/{$pay->id}", ['amount' => 100000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertOk();
        $this->assertDatabaseHas('purchases', ['id' => $p['id'], 'paid_amount' => 100000, 'payment_status' => 'paid']);
        $this->putJson("/api/transactions/supplier_payment/{$pay->id}", ['amount' => 100001, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertStatus(409);

        $advance = \App\Models\Advance::create(['supplier_id' => $seller->id, 'amount' => 5000, 'used' => 3000, 'method' => 'cash', 'paid_at' => today(), 'created_by' => 1]);
        $this->putJson("/api/transactions/supplier_advance/{$advance->id}", ['amount' => 2000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertStatus(409);
        $this->putJson("/api/transactions/supplier_advance/{$advance->id}", ['amount' => 7000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertOk();
    }

    public function test_a_charge_adds_to_the_due_and_is_collected_after_the_sale_balance(): void
    {
        $v = $this->stockVehicle('CHG-1');
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 900000]))->assertCreated()->json('data'); // 100,000 due

        $charge = $this->postJson("/api/sales/{$sale['id']}/charges", ['purpose' => 'Late fee', 'amount' => 5000, 'charged_on' => today()->toDateString()])->assertCreated()->json('data');
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'charges_total' => 5000, 'due_amount' => 105000]);

        // 102,000: the 100,000 balance first, then 2,000 of the charge
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $sale['customer_id'], 'amount' => 102000, 'method' => 'cash'])->assertCreated();
        $this->assertDatabaseHas('payments', ['sale_id' => $sale['id'], 'sale_charge_id' => null, 'amount' => 100000, 'note' => null]);
        $this->assertDatabaseHas('payments', ['sale_charge_id' => $charge['id'], 'amount' => 2000]);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'due_amount' => 3000]);

        // the charge shows on the statement, can be raised but not below what is paid, and is not deletable once paid
        $this->getJson("/api/reports/person-transactions?side=customer&customer_id={$sale['customer_id']}")->assertOk()
            ->assertJsonFragment(['description' => 'Late fee', 'charge' => 5000]);
        $this->putJson("/api/charges/{$charge['id']}", ['purpose' => 'Late fee', 'amount' => 1000, 'charged_on' => today()->toDateString()])->assertStatus(409);
        $this->putJson("/api/charges/{$charge['id']}", ['purpose' => 'Late fee', 'amount' => 6000, 'charged_on' => today()->toDateString()])->assertOk();
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'due_amount' => 4000]);
        $this->deleteJson("/api/charges/{$charge['id']}")->assertStatus(409);
        $this->getJson("/api/charges/{$charge['id']}")->assertOk()->assertJsonPath('data.edits.0.changes.0.field', 'Amount');
    }

    public function test_charge_payments_on_an_installment_sale_come_after_the_installments(): void
    {
        $v = $this->stockVehicle('CHG-2');
        $plan = ['installment_count' => 2, 'first_due_date' => today()->addMonth()->toDateString(), 'interest_rate' => 0];
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'installment', 'down_payment' => 800000] + $plan))->assertCreated()->json('data'); // 2 x 100,000
        $charge = $this->postJson("/api/sales/{$sale['id']}/charges", ['purpose' => 'Documents', 'amount' => 3000, 'charged_on' => today()->toDateString()])->assertCreated()->json('data');

        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $sale['customer_id'], 'amount' => 203000, 'method' => 'cash'])->assertCreated();
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'due_amount' => 0, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('payments', ['sale_charge_id' => $charge['id'], 'amount' => 3000]);
        $this->assertDatabaseCount('installments', 2);
    }

    public function test_a_cancelled_sale_cannot_be_edited(): void
    {
        $v = $this->stockVehicle('EDIT-3');
        $sale = $this->postJson('/api/sales', $this->saleData($v))->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'test'])->assertOk();

        $this->putJson("/api/sales/{$sale['id']}", $this->editBody($sale, ['memo_no' => 'X']))->assertStatus(409);
    }

    public function test_cannot_buy_vehicle_that_is_in_stock(): void
    {
        $v = $this->stockVehicle();
        $seller = Supplier::first() ?? Supplier::create(['name' => 'Any', 'phone' => '0199']);

        $this->postJson('/api/purchases', [
            'vehicle_id' => $v->id, 'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 1,
        ])->assertStatus(409);
    }

    public function test_deleting_a_purchase_removes_the_vehicle_or_restores_its_last_sale(): void
    {
        // the only purchase: the vehicle goes with it
        $v = $this->stockVehicle('CH-DEL');
        $this->deleteJson("/api/purchases/{$v->current_purchase_id}")->assertOk();
        $this->assertSoftDeleted('vehicles', ['id' => $v->id]);

        // a buy-back: the vehicle goes back to sold, under the sale before it
        $v = $this->stockVehicle('CH-BACK');
        $firstPurchase = $v->current_purchase_id;
        $saleId = $this->postJson('/api/sales', $this->saleData($v))->assertCreated()->json('data.id');
        $buyBack = $this->postJson('/api/purchases', [
            'vehicle_id' => $v->id, 'source' => 'customer', 'customer_id' => Sale::findOrFail($saleId)->customer_id,
            'purchase_date' => today()->toDateString(), 'price' => 700000,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/purchases/{$buyBack}")->assertOk();
        $this->assertDatabaseHas('vehicles', ['id' => $v->id, 'status' => 'sold', 'current_purchase_id' => $firstPurchase, 'current_sale_id' => $saleId]);
    }

    public function test_cash_sale_is_paid_and_vehicle_becomes_sold(): void
    {
        $v = $this->stockVehicle();

        $this->postJson('/api/sales', $this->saleData($v))->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.due_amount', 0);

        $this->assertSame('sold', $v->fresh()->status);
        $this->postJson('/api/sales', $this->saleData($v))->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    }

    public function test_due_sale_promise_date_is_optional_and_balance_is_tracked(): void
    {
        $v = $this->stockVehicle();

        // no promise date: the sale still carries its due, there is just no promise to track
        $plain = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'down_payment' => 400000]))
            ->assertCreated()->assertJsonPath('data.due_amount', 600000)->json('data');
        $this->assertDatabaseMissing('due_promises', ['sale_id' => $plain['id']]);
        $this->postJson("/api/sales/{$plain['id']}/cancel", ['reason' => 'test'])->assertOk();

        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => today()->addDays(10)->toDateString(),
        ]))->assertCreated()->assertJsonPath('data.due_amount', 600000)->assertJsonPath('data.payment_status', 'partial')->json('data');

        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 700000])->assertStatus(409);
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 600000])->assertCreated()
            ->assertJsonPath('data.sale.payment_status', 'paid');

        $this->assertDatabaseHas('due_promises', ['sale_id' => $sale['id'], 'status' => 'kept']);
    }

    public function test_installment_schedule_remainder_goes_to_last_and_investor_is_credited(): void
    {
        $v = $this->stockVehicle();
        $investor = Investor::create(['name' => 'Inv']);

        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'installment', 'down_payment' => 400000, 'investor_id' => $investor->id,
            'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(),
            'interest_rate' => 10, 'investor_share_percent' => 50,
        ]))->assertCreated()->json('data');

        // principal 600000 + 10% = 660000 over 3 installments
        $this->assertEquals(660000, $sale['plan']['total_payable']);
        $amounts = collect($sale['plan']['installments'])->pluck('amount')->all();
        $this->assertEquals([220000, 220000, 220000], $amounts);
        $this->assertEquals(1060000, $sale['grand_total']);

        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 330000])->assertCreated();

        $this->assertSame(['paid', 'partial', 'pending'], Installment::orderBy('seq_no')->pluck('status')->all());
        // 330000 collected: principal part 300000, interest part 30000, investor profit 15000
        $this->assertDatabaseHas('investor_settlements', ['investor_id' => $investor->id, 'type' => 'principal_return', 'amount' => 300000]);
        $this->assertDatabaseHas('investor_settlements', ['investor_id' => $investor->id, 'type' => 'profit_share', 'amount' => 15000]);

        $this->getJson("/api/investors/{$investor->id}/portfolio")->assertOk()
            ->assertJsonPath('data.summary.invested', 600000)->assertJsonPath('data.summary.outstanding', 300000);
    }

    public function test_installment_without_investor_and_with_interest_as_amount(): void
    {
        $v = $this->stockVehicle();

        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'installment', 'down_payment' => 400000,
            'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(),
            'interest_amount' => 60000,
        ]))->assertCreated()->json('data');

        // principal 600000 + 60000 interest = 660000, the shop finances it itself
        $this->assertEquals(660000, $sale['plan']['total_payable']);
        $this->assertEquals(10, $sale['plan']['interest_rate']);
        $this->assertNull($sale['investor']);

        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 220000])->assertCreated();
        $this->assertDatabaseCount('investor_settlements', 0);
    }

    public function test_supplier_due_can_be_paid_in_parts_and_from_an_advance(): void
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01700099999']);
        $purchase = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'PAY-1', 'engine_no' => 'EN-PAY-1', 'supplier_id' => $seller->id,
            'purchase_date' => today()->toDateString(), 'price' => 500000, 'paid_amount' => 100000,
        ])->assertCreated()->json('data');

        $this->getJson('/api/payables')->assertOk()->assertJsonPath('data.0.balance', 400000)->assertJsonPath('data.0.advance_available', 0);

        // part payment
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 150000, 'method' => 'bank'])->assertCreated()->assertJsonPath('data.balance', 250000);
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 300000, 'method' => 'cash'])->assertStatus(409);

        // an advance paid earlier, then used on the purchase
        $this->postJson('/api/advances', ['supplier_id' => $seller->id, 'amount' => 100000, 'method' => 'cash'])->assertCreated();
        $this->getJson('/api/payables')->assertJsonPath('data.0.advance_available', 100000);
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 150000, 'method' => 'advance'])->assertStatus(409); // only 100,000 of advance
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 100000, 'method' => 'advance'])->assertCreated()->assertJsonPath('data.balance', 150000);
        $this->assertDatabaseHas('advances', ['supplier_id' => $seller->id, 'used' => 100000]);

        // the rest in cash clears it
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 150000, 'method' => 'cash'])->assertCreated()->assertJsonPath('data.balance', 0);
        $this->assertDatabaseHas('purchases', ['id' => $purchase['id'], 'paid_amount' => 500000, 'payment_status' => 'paid']);
        $this->getJson('/api/payables')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1, 'method' => 'cash'])->assertStatus(409);
        $this->deleteJson("/api/purchases/{$purchase['id']}")->assertStatus(409);
    }

    public function test_amounts_in_paisa_add_up_exactly(): void
    {
        $v = $this->stockVehicle();

        // 1000.30 - 0.10 leaves 1000.20 due; as floats, 0.10 + 0.20 + ... would leave crumbs like 0.0000000000002
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['payment_type' => 'due', 'sale_price' => 1000.30, 'down_payment' => 0.10]))
            ->assertCreated()->assertJsonPath('data.due_amount', 1000.2)->json('data');

        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 1.20])->assertCreated();
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 999.01])->assertStatus(409); // only 999.00 is left
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 999.00])->assertCreated()
            ->assertJsonPath('data.sale.due_amount', 0)->assertJsonPath('data.sale.total_paid', 1000.3)->assertJsonPath('data.sale.payment_status', 'paid');

        // an installment plan of 100.00 over 3 is 33.33 + 33.33 + 33.34
        $v2 = $this->stockVehicle('CH-PAISA');
        $plan = $this->postJson('/api/sales', $this->saleData($v2, [
            'payment_type' => 'installment', 'sale_price' => 100.10, 'down_payment' => 0.10,
            'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(),
        ]))->assertCreated()->json('data.plan');
        $this->assertEquals([33.33, 33.33, 33.34], collect($plan['installments'])->pluck('amount')->all());
    }

    public function test_a_purchase_from_a_customer_cannot_be_paid_from_an_advance(): void
    {
        $purchase = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'ADV-C', 'engine_no' => 'EN-ADV-C', 'source' => 'customer', 'customer_id' => $this->buyer()->id,
            'purchase_date' => today()->toDateString(), 'price' => 100000,
        ])->assertCreated()->json('data');

        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1000, 'method' => 'advance'])->assertStatus(409);
        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated()->assertJsonPath('data.balance', 99000);
    }

    public function test_paid_amount_cannot_be_edited_once_payments_are_recorded(): void
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01700088888']);
        $purchase = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'EDIT-1', 'engine_no' => 'EN-EDIT-1', 'supplier_id' => $seller->id,
            'purchase_date' => today()->toDateString(), 'price' => 100000, 'paid_amount' => 10000,
        ])->assertCreated()->json('data');

        // before any payment, the amount paid at purchase can still be corrected
        $this->putJson("/api/purchases/{$purchase['id']}", ['paid_amount' => 20000])->assertOk()->assertJsonPath('data.paid_amount', 20000);

        $this->postJson("/api/purchases/{$purchase['id']}/payments", ['amount' => 40000, 'method' => 'cash'])->assertCreated();
        $this->putJson("/api/purchases/{$purchase['id']}", ['paid_amount' => 0])->assertStatus(409);
        $this->putJson("/api/purchases/{$purchase['id']}", ['paid_amount' => 60000, 'notes' => 'same total is fine'])->assertOk();
        $this->assertDatabaseHas('purchases', ['id' => $purchase['id'], 'paid_amount' => 60000, 'payment_status' => 'partial']);
    }

    public function test_cancelling_an_investor_sale_returns_the_principal_still_out(): void
    {
        $v = $this->stockVehicle();
        $investor = Investor::create(['name' => 'Inv']);
        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'installment', 'down_payment' => 400000, 'investor_id' => $investor->id,
            'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(),
            'interest_rate' => 10, 'investor_share_percent' => 50,
        ]))->assertCreated()->json('data');

        // 330000 collected returns 300000 of the 600000 principal; cancelling returns the other 300000
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 330000])->assertCreated();
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'Buyer backed out'])->assertOk();

        $this->assertDatabaseHas('investor_settlements', ['investor_id' => $investor->id, 'type' => 'principal_return', 'amount' => 300000, 'note' => "Auto: sale #{$sale['id']} cancelled, principal returned"]);
        $this->getJson("/api/investors/{$investor->id}/portfolio")->assertOk()
            ->assertJsonPath('data.summary.invested', 600000)->assertJsonPath('data.summary.returned', 600000)
            ->assertJsonPath('data.summary.outstanding', 0);
    }

    public function test_account_form_pays_suppliers_and_collects_from_customers_oldest_first(): void
    {
        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01700088888']);
        $buy = fn (string $chassis, string $date) => $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => "E-$chassis", 'supplier_id' => $seller->id,
            'purchase_date' => $date, 'price' => 100000, 'paid_amount' => 0,
        ])->assertCreated()->json('data');
        $first = $buy('ACC-1', today()->subDays(5)->toDateString());
        $second = $buy('ACC-2', today()->subDays(2)->toDateString());

        $this->getJson("/api/accounts?side=supplier&supplier_id={$seller->id}")->assertOk()
            ->assertJsonPath('data.total_due', 200000)->assertJsonPath('data.items.0.id', $first['id']);

        // 150,000 clears the older purchase and half of the newer one
        $this->postJson('/api/accounts', ['side' => 'supplier', 'supplier_id' => $seller->id, 'amount' => 150000, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.total_due', 50000);
        $this->assertDatabaseHas('purchases', ['id' => $first['id'], 'paid_amount' => 100000, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('purchases', ['id' => $second['id'], 'paid_amount' => 50000, 'payment_status' => 'partial']);

        // paying more than is owed saves the rest as an advance
        $this->postJson('/api/accounts', ['side' => 'supplier', 'supplier_id' => $seller->id, 'amount' => 80000, 'method' => 'bank'])
            ->assertCreated()->assertJsonPath('data.total_due', 0)->assertJsonPath('data.advance_available', 30000);

        // the statement agrees: 200,000 bought, 230,000 paid (30,000 of it ahead)
        $this->getJson("/api/reports/person-transactions?side=supplier&supplier_id={$seller->id}")->assertOk()
            ->assertJsonPath('data.total_charge', 200000)->assertJsonPath('data.total_paid', 230000)->assertJsonPath('data.closing_balance', -30000);

        // a customer: oldest sale first, never more than they owe
        $buyer = $this->buyer();
        $v1 = $this->stockVehicle('ACC-3');
        $v2 = $this->stockVehicle('ACC-4');
        foreach ([[$v1, -4], [$v2, -2]] as [$v, $off]) {
            $this->postJson('/api/sales', $this->saleData($v, [
                'customer_id' => $buyer->id, 'sale_date' => today()->addDays($off)->toDateString(), 'sale_price' => 100000,
                'payment_type' => 'due', 'down_payment' => 40000,
            ]))->assertCreated();
        }
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $buyer->id, 'amount' => 200000, 'method' => 'cash'])->assertStatus(409);
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $buyer->id, 'amount' => 90000, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('data.total_due', 30000)->assertJsonPath('data.items.0.current_due', 30000)->assertJsonPath('data.days_late', 0);
        $this->assertDatabaseHas('sales', ['vehicle_id' => $v1->id, 'due_amount' => 0, 'payment_status' => 'paid']);
        $this->assertDatabaseHas('sales', ['vehicle_id' => $v2->id, 'due_amount' => 30000]);

        // one vehicle only: limited to that vehicle's due
        $sale2 = Sale::where('vehicle_id', $v2->id)->first();
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $buyer->id, 'sale_id' => $sale2->id, 'amount' => 30001, 'method' => 'cash'])->assertStatus(409);
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $buyer->id, 'sale_id' => $sale2->id, 'amount' => 10000, 'method' => 'cash', 'memo_no' => 'M-77'])->assertCreated();
        $this->assertDatabaseHas('payments', ['sale_id' => $sale2->id, 'amount' => 10000, 'memo_no' => 'M-77']);
        $this->assertDatabaseHas('sales', ['vehicle_id' => $v2->id, 'due_amount' => 20000]);
        $this->getJson("/api/reports/person-transactions?side=customer&customer_id={$buyer->id}")->assertOk()
            ->assertJsonPath('data.closing_balance', 20000)->assertJsonPath('data.total_charge', 200000);
        $this->getJson("/api/reports/person-transactions?side=customer&customer_id={$buyer->id}&from=".today()->addDay()->toDateString())->assertOk()
            ->assertJsonPath('data.opening_balance', 20000)->assertJsonCount(0, 'data.rows');
        $all = $this->getJson('/api/reports/transactions?side=customer')->assertOk();
        $this->assertSame(200000.0, (float) $all->json('data.total_charge'));
        $this->assertSame($buyer->id, $all->json('data.rows.0.person_id'));
        $this->assertStringStartsWith('CP-', $all->json('data.rows.0.no')); // newest first: the latest payment, and the sale entry last
        $this->assertStringStartsWith('SALE-', $all->json('data.rows.'.(count($all->json('data.rows')) - 1).'.no'));
        $this->assertNotEmpty($all->json('data.rows.0.details'));
        $day = $this->getJson('/api/reports/daily')->assertOk();
        $investor = Investor::create(['name' => 'Daily Inv', 'phone' => '01700099999']);
        $this->postJson('/api/investor-settlements', ['investor_id' => $investor->id, 'type' => 'investment', 'amount' => 40000, 'date' => today()->toDateString()])->assertCreated();
        $this->postJson('/api/investor-settlements', ['investor_id' => $investor->id, 'type' => 'profit_share', 'amount' => 3000, 'date' => today()->toDateString()])->assertCreated();
        $this->getJson('/api/reports/daily')->assertOk()->assertJsonPath('data.investor_received.total', 40000)->assertJsonPath('data.investor_received.count', 1)->assertJsonPath('data.investor_paid.total', 3000);
        $this->getJson('/api/reports/investor-transactions?type=investment')->assertOk()->assertJsonPath('data.totals.investment', 40000)->assertJsonPath('data.totals.profit_share', 0);
        $this->getJson('/api/reports/investor-transactions')->assertOk()->assertJsonPath('data.totals.profit_share', 3000);
        $day->assertJsonPath('data.collections.total', 100000)->assertJsonPath('data.supplier_payments.total', 230000);
        $this->getJson('/api/reports/daily?date='.today()->subDays(2)->toDateString())->assertOk()
            ->assertJsonPath('data.sales.count', 1)->assertJsonPath('data.sales.received', 40000)->assertJsonPath('data.sales.due', 60000);
        $customers = collect($this->getJson('/api/reports/person-summary?side=customer')->assertOk()->json('data.rows'))->firstWhere('person.id', $buyer->id);
        $this->assertSame(20000, (int) $customers['closing_balance']);
        $suppliers = collect($this->getJson('/api/reports/person-summary?side=supplier')->assertOk()->json('data.rows'))->firstWhere('person.id', $seller->id);
        $this->assertSame(-30000, (int) $suppliers['closing_balance']);
    }

    public function test_installment_rounding_remainder_on_last(): void
    {
        $v = $this->stockVehicle();
        $investor = Investor::create(['name' => 'Inv']);

        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'installment', 'down_payment' => 0, 'investor_id' => $investor->id,
            'installment_count' => 3, 'first_due_date' => today()->addMonth()->toDateString(), 'sale_price' => 1000,
        ]))->assertCreated()->json('data');

        $this->assertEquals([333.33, 333.33, 333.34], collect($sale['plan']['installments'])->pluck('amount')->all());
    }

    public function test_cancel_returns_vehicle_to_stock_and_cancels_schedule(): void
    {
        $v = $this->stockVehicle();
        $investor = Investor::create(['name' => 'Inv']);
        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'payment_type' => 'installment', 'down_payment' => 400000, 'investor_id' => $investor->id,
            'installment_count' => 2, 'first_due_date' => today()->addMonth()->toDateString(),
        ]))->json('data');

        $this->postJson("/api/sales/{$sale['id']}/cancel", [])->assertStatus(422);
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'Buyer backed out', 'refund_amount' => 400000])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('in_stock', $v->fresh()->status);
        $this->assertSame(0, Installment::where('status', '!=', 'cancelled')->count());
        $this->assertDatabaseHas('payments', ['sale_id' => $sale['id'], 'amount' => -400000]);
        $this->getJson("/api/investors/{$investor->id}/portfolio")->assertJsonPath('data.summary.outstanding', 0);

        // can be sold again: history keeps both sales
        $this->postJson('/api/sales', $this->saleData($v))->assertCreated();
        $this->assertCount(2, $v->sales);
    }

    public function test_staff_cannot_cancel(): void
    {
        $v = $this->stockVehicle();
        $sale = $this->postJson('/api/sales', $this->saleData($v))->json('data');

        Sanctum::actingAs(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'x'])->assertForbidden();
    }

    public function test_investor_profit_formula_is_saved_with_its_rates(): void
    {
        $investor = Investor::create(['name' => 'Formula Inv', 'phone' => '01700077777']);

        $this->putJson("/api/investors/{$investor->id}/profit-formula", ['invested' => 30000, 'profit' => 8800, 'months' => 24])
            ->assertOk()->assertJsonPath('data.formula_invested', 30000)->assertJsonPath('data.formula_profit', 8800)
            ->assertJsonPath('data.formula_months', 24)->assertJsonPath('data.formula_rate_month', 1.2222)->assertJsonPath('data.formula_rate_year', 14.6664);
        $this->putJson("/api/investors/{$investor->id}/profit-formula", ['invested' => 0, 'profit' => 10, 'months' => 12])->assertStatus(422);
        $this->putJson("/api/investors/{$investor->id}/profit-formula", ['invested' => 1000, 'profit' => 10])->assertStatus(422);
    }

    public function test_investor_vehicle_payments_lists_each_financed_vehicle(): void
    {
        $investor = Investor::create(['name' => 'VP Inv', 'phone' => '01700066666']);
        $this->getJson("/api/investors/{$investor->id}/vehicle-payments")->assertOk()->assertJsonPath('data.rows', [])->assertJsonPath('data.totals.principal_due', 0);
    }

    public function test_registration_moves_one_step_only(): void
    {
        $v = $this->stockVehicle();
        $sale = $this->postJson('/api/sales', $this->saleData($v))->json('data');

        $this->patchJson("/api/sales/{$sale['id']}/registration", ['status' => 'file_received'])->assertStatus(409);
        $this->patchJson("/api/sales/{$sale['id']}/registration", ['status' => 'bank_chalan_done'])->assertOk();
        $this->patchJson("/api/sales/{$sale['id']}/registration", ['status' => 'pending'])->assertStatus(409);
        $this->patchJson("/api/sales/{$sale['id']}/registration", ['status' => 'pending', 'note' => 'Wrong chalan'])->assertOk();
        $this->assertDatabaseCount('registration_logs', 2);
    }

    public function test_vehicle_history_and_error_format(): void
    {
        $v = $this->stockVehicle();
        $this->postJson('/api/sales', $this->saleData($v))->assertCreated();

        $types = collect($this->getJson("/api/vehicles/{$v->id}/history")->assertOk()->json('data.timeline'))->pluck('type')->all();
        $this->assertContains('purchase', $types);
        $this->assertContains('sale', $types);
        $this->assertContains('payment', $types);

        $this->getJson('/api/sales/9999')->assertNotFound()->assertJsonPath('success', false);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['data' => ['stock_count', 'total_due', 'overdue_count']]);
    }

    public function test_purchase_keeps_full_details_and_refreshes_on_repurchase(): void
    {
        $seller = Supplier::create(['name' => 'Sup', 'phone' => '0199']);

        $data = $this->postJson('/api/purchases', [
            'vehicle_type' => 'cng', 'chassis_no' => 'D-1', 'engine_no' => 'D-E1', 'registration_no' => 'DHA-1', 'brand' => 'Bajaj', 'mileage' => 42000,
            'papers_received' => true, 'registration_date' => '2020-05-01', 'tax_token_expiry' => '2027-01-01', 'condition_notes' => 'Good',
            'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 300000, 'paid_amount' => 100000,
            'payment_method' => 'bank', 'payment_reference' => 'TRX-9',
        ])->assertCreated()->assertJsonPath('data.payment_method', 'bank')->json('data');

        $vehicle = Vehicle::find($data['vehicle_id']);
        $this->assertSame(42000, $vehicle->mileage);
        $this->assertTrue($vehicle->papers_received);

        // sell, then repurchase: vehicle details are refreshed
        $this->postJson('/api/sales', $this->saleData($vehicle))->assertCreated();
        $this->postJson('/api/purchases', [
            'vehicle_id' => $vehicle->id, 'registration_no' => 'DHA-1', 'mileage' => 55000, 'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 1,
        ])->assertCreated();
        $this->assertSame(55000, $vehicle->fresh()->mileage);

    }

    public function test_shop_can_add_a_new_colour_once(): void
    {
        $this->postJson('/api/vehicle-colors', ['name' => 'pearl white'])->assertCreated()->assertJsonPath('data', 'Pearl white');
        $this->postJson('/api/vehicle-colors', ['name' => 'Pearl white'])->assertStatus(422);
        $this->postJson('/api/vehicle-colors', ['name' => 'Red'])->assertStatus(422)->assertJsonPath('errors.name.0', 'This colour is already in the list.');
        $this->getJson('/api/vehicle-colors')->assertOk()->assertJsonPath('data.0', 'Pearl white');
    }

    public function test_purchase_and_sales_reports_total_correctly_and_filter(): void
    {
        $seller = Supplier::create(['name' => 'Sup', 'phone' => '0188']);
        $buy = fn (string $ch, string $type, int $price, string $date) => $this->postJson('/api/purchases', [
            'vehicle_type' => $type, 'chassis_no' => $ch, 'engine_no' => "E-$ch", 'supplier_id' => $seller->id,
            'purchase_date' => $date, 'price' => $price, 'paid_amount' => 100, 'cost_extra' => 50,
        ])->assertCreated()->json('data.vehicle_id');

        $cng = $buy('R-1', 'cng', 1000, today()->toDateString());
        $buy('R-2', 'motorcycle', 500, today()->subDays(40)->toDateString());

        $this->getJson('/api/reports/purchases')->assertOk()
            ->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.totals.price', 1500)->assertJsonPath('data.totals.total_cost', 1600)
            ->assertJsonPath('data.totals.paid', 200)->assertJsonPath('data.totals.unpaid', 1300);
        $this->getJson('/api/reports/purchases?from='.today()->subDays(7)->toDateString())->assertJsonPath('data.totals.count', 1);
        $this->getJson('/api/reports/purchases?vehicle_type=motorcycle')->assertJsonPath('data.totals.price', 500);

        // sell the CNG for 1400: profit = 1400 - (1000 + 50); a cancelled sale is listed but not counted
        $sale = $this->postJson('/api/sales', $this->saleData(Vehicle::find($cng), ['sale_price' => 1400, 'down_payment' => 1400]))->assertCreated()->json('data');
        $this->getJson('/api/reports/sales')->assertOk()
            ->assertJsonPath('data.totals.count', 1)->assertJsonPath('data.totals.sales_amount', 1400)->assertJsonPath('data.totals.profit', 350)
            ->assertJsonPath('data.by_type.0.type', 'cng');

        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'test'])->assertOk();
        $this->getJson('/api/reports/sales')->assertJsonPath('data.totals.count', 0)->assertJsonPath('data.totals.cancelled', 1)->assertJsonCount(1, 'data.sales');

        // reports need the reports permission
        Sanctum::actingAs(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]));
        $this->getJson('/api/reports/sales')->assertForbidden();
    }

    public function test_vehicle_can_be_looked_up_by_registration_number(): void
    {
        $seller = Supplier::create(['name' => 'Sup', 'phone' => '0166']);
        $this->postJson('/api/purchases', [
            'vehicle_type' => 'car', 'chassis_no' => 'L-1', 'engine_no' => 'L-E1', 'registration_no' => 'DHA-55', 'registration_date' => '2021-03-04',
            'tax_token_expiry' => '2027-03-04', 'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 10,
        ])->assertCreated();

        $this->getJson('/api/vehicles/lookup?registration_no=DHA-55')->assertOk()
            ->assertJsonPath('data.chassis_no', 'L-1')->assertJsonPath('data.registration_date', '2021-03-04')->assertJsonPath('data.tax_token_expiry', '2027-03-04');
        $this->getJson('/api/vehicles/lookup?registration_no=NOPE')->assertOk()->assertJsonPath('data', null);
        $this->getJson('/api/vehicles/lookup')->assertStatus(422);
    }

    public function test_purchase_is_from_a_supplier_or_a_customer_by_its_source(): void
    {
        $supplier = Supplier::create(['name' => 'Sup', 'phone' => '0155']);
        $customer = Customer::create(['name' => 'Cus', 'phone' => '0144']);
        $buy = fn (string $source, array $who, string $ch) => $this->postJson('/api/purchases', $who + [
            'source' => $source, 'vehicle_type' => 'cng', 'chassis_no' => $ch, 'engine_no' => "E-$ch",
            'purchase_date' => today()->toDateString(), 'price' => 100,
        ]);

        $buy('supplier', ['supplier_id' => $supplier->id], 'S-1')->assertCreated()->assertJsonPath('data.source', 'supplier')->assertJsonPath('data.seller.name', 'Sup');
        $buy('customer', ['customer_id' => $customer->id], 'S-2')->assertCreated()->assertJsonPath('data.source', 'customer')->assertJsonPath('data.seller.name', 'Cus');
        $this->assertDatabaseHas('purchases', ['source' => 'customer', 'customer_id' => $customer->id, 'supplier_id' => null]);

        // each tab needs its own kind of seller
        $buy('supplier', [], 'S-4')->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        $buy('customer', [], 'S-5')->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $buy('supplier', ['supplier_id' => $customer->id + 999], 'S-6')->assertStatus(422)->assertJsonValidationErrors('supplier_id');

        $this->getJson('/api/reports/purchases?source=customer')->assertJsonPath('data.totals.count', 1);
        $this->getJson("/api/reports/purchases?supplier_id={$supplier->id}")->assertJsonPath('data.totals.count', 1);
    }

    public function test_vehicles_with_customers_can_be_found_by_name_phone_engine_or_chassis(): void
    {
        $v = $this->stockVehicle('F-CH-9');
        $buyer = Customer::create(['name' => 'Rahman Ali', 'phone' => '01755001122', 'alt_phone' => '01855003344', 'nid' => '1998123456789']);
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['customer_id' => $buyer->id, 'payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => today()->addDays(9)->toDateString()]))->assertCreated()->json('data');

        foreach (['rahman', '0175500', '01855003344', '1998123456789', 'EN-F-CH-9', 'F-CH-9'] as $term) {
            $this->getJson('/api/vehicles/owned?search='.$term)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale['id'])->assertJsonPath('data.0.customer.name', 'Rahman Ali')->assertJsonPath('data.0.customer.nid', '1998123456789')
                ->assertJsonPath('data.0.due_amount', 600000)->assertJsonPath('data.0.vehicle.chassis_no', 'F-CH-9');
        }
        $this->getJson('/api/vehicles/owned?search=nobody')->assertJsonCount(0, 'data');
        $this->getJson('/api/vehicles/owned?search=r')->assertJsonCount(0, 'data');     // too short to search

        // once the sale is cancelled the vehicle is back in stock, so it is no longer "with a customer"
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'x'])->assertOk();
        $this->getJson('/api/vehicles/owned?search=rahman')->assertJsonCount(0, 'data');
    }

    public function test_lookup_tells_which_customer_has_the_vehicle(): void
    {
        $v = $this->stockVehicle('W-CH-1');
        $this->getJson('/api/vehicles/lookup?chassis_no=W-CH-1')->assertOk()->assertJsonPath('data.current_sale', null);

        $buyer = Customer::create(['name' => 'Holder', 'phone' => '01990001111']);
        $sale = $this->postJson('/api/sales', $this->saleData($v, ['customer_id' => $buyer->id]))->assertCreated()->json('data');

        $this->getJson('/api/vehicles/lookup?chassis_no=W-CH-1')->assertOk()
            ->assertJsonPath('data.current_sale.id', $sale['id'])->assertJsonPath('data.current_sale.customer.name', 'Holder')
            ->assertJsonPath('data.current_sale.customer_id', $buyer->id)->assertJsonPath('data.current_sale.status', 'active');
    }

    public function test_due_can_be_adjusted_against_a_buy_back_from_the_customer(): void
    {
        $v = $this->stockVehicle('B-CH-1');
        $customer = Customer::create(['name' => 'Returner', 'phone' => '01990002222']);
        $other = Customer::create(['name' => 'Someone else', 'phone' => '01990003333']);
        $sale = $this->postJson('/api/sales', $this->saleData($v, [
            'customer_id' => $customer->id, 'payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => today()->addDays(9)->toDateString(),
        ]))->assertCreated()->json('data'); // price 1,000,000, due 600,000

        $buy = fn (array $extra) => $this->postJson('/api/purchases', $extra + [
            'source' => 'customer', 'vehicle_id' => $v->id, 'customer_id' => $customer->id, 'purchase_date' => today()->toDateString(), 'price' => 700000,
        ]);

        // guard rails
        $buy(['due_adjustment' => 650000, 'adjusted_sale_id' => $sale['id']])->assertStatus(409);                       // more than is owed
        $buy(['due_adjustment' => 100000, 'adjusted_sale_id' => $sale['id'], 'paid_amount' => 650000])->assertStatus(409); // more than the price
        $buy(['due_adjustment' => 100000])->assertStatus(409);                                                          // no sale chosen
        $buy(['due_adjustment' => 100000, 'adjusted_sale_id' => $sale['id'], 'customer_id' => $other->id])->assertStatus(409); // another customer's

        // a different vehicle of the same customer: its sale cannot be used, only the returned vehicle's own sale
        $second = $this->stockVehicle('B-CH-2');
        $secondSale = $this->postJson('/api/sales', $this->saleData($second, [
            'customer_id' => $customer->id, 'payment_type' => 'due', 'down_payment' => 100000, 'promised_date' => today()->addDays(9)->toDateString(),
        ]))->assertCreated()->json('data');
        $buy(['due_adjustment' => 100000, 'adjusted_sale_id' => $secondSale['id']])->assertStatus(409)
            ->assertJsonPath('message', 'The due can only be adjusted against the sale of the vehicle being bought back.');
        $this->assertSame('sold', $v->fresh()->status);

        // adjust 500,000 of the 600,000 due, pay the customer the remaining 200,000 in cash
        $purchase = $buy(['due_adjustment' => 500000, 'adjusted_sale_id' => $sale['id'], 'paid_amount' => 200000])
            ->assertCreated()->assertJsonPath('data.due_adjustment', 500000)->assertJsonPath('data.payment_status', 'paid')->json('data');

        $this->getJson("/api/sales/{$sale['id']}")->assertOk()->assertJsonPath('data.due_amount', 100000)->assertJsonPath('data.total_paid', 900000);
        $this->assertDatabaseHas('payments', ['sale_id' => $sale['id'], 'amount' => 500000, 'method' => 'adjustment']);
        $this->assertSame('in_stock', $v->fresh()->status);

        // a set-off purchase cannot be deleted
        $this->deleteJson("/api/purchases/{$purchase['id']}")->assertStatus(409);
    }

    public function test_purchase_keeps_the_sellers_memo_number_unique_per_seller(): void
    {
        $a = Supplier::create(['name' => 'Seller A', 'phone' => '0171110001']);
        $b = Supplier::create(['name' => 'Seller B', 'phone' => '0171110002']);
        $buy = fn (Supplier $seller, string $chassis, ?string $memo) => $this->postJson('/api/purchases', array_filter([
            'vehicle_type' => 'cng', 'chassis_no' => $chassis, 'engine_no' => "E-$chassis", 'supplier_id' => $seller->id,
            'purchase_date' => today()->toDateString(), 'price' => 100, 'memo_no' => $memo,
        ]));

        $buy($a, 'M-1', 'MEMO-501')->assertCreated()->assertJsonPath('data.memo_no', 'MEMO-501');
        $buy($a, 'M-2', 'MEMO-501')->assertStatus(422)->assertJsonPath('errors.memo_no.0', 'This memo number is already recorded for this seller.');
        $buy($b, 'M-3', 'MEMO-501')->assertCreated();   // another seller can use the same number
        $buy($a, 'M-4', null)->assertCreated();         // memo is optional
        $buy($a, 'M-5', null)->assertCreated();         // and many purchases may have none

        $this->getJson('/api/purchases?search=MEMO-501')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/reports/purchases')->assertJsonPath('data.purchases.0.memo_no', null);
    }

    public function test_purchases_and_sales_can_be_saved_as_drafts_without_side_effects(): void
    {
        $v = $this->stockVehicle('DR-1');

        // a draft keeps half-filled data as it is: no validation, no effect on stock or money
        $draft = $this->postJson('/api/drafts', ['type' => 'sale', 'label' => 'DR-1 sale', 'data' => ['vehicle_id' => (string) $v->id, 'sale_price' => '', 'payment_type' => 'due']])
            ->assertCreated()->assertJsonPath('data.label', 'DR-1 sale')->assertJsonPath('data.data.payment_type', 'due')->json('data');
        $this->assertSame('in_stock', $v->fresh()->status);
        $this->assertDatabaseCount('sales', 0);

        $this->postJson('/api/drafts', ['type' => 'purchase', 'data' => ['chassis_no' => 'NEW-1']])->assertCreated();
        $this->getJson('/api/drafts?type=sale')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/drafts?type=purchase')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/drafts')->assertStatus(422);                                   // type is required

        // update, then delete
        $this->putJson("/api/drafts/{$draft['id']}", ['label' => 'Updated', 'data' => ['sale_price' => '900000']])->assertOk()->assertJsonPath('data.data.sale_price', '900000');
        $this->putJson("/api/drafts/{$draft['id']}", ['type' => 'purchase', 'data' => ['x' => 1]])->assertStatus(422);  // cannot change kind
        $this->deleteJson("/api/drafts/{$draft['id']}")->assertOk();
        $this->getJson('/api/drafts?type=sale')->assertJsonCount(0, 'data');

        // empty / invalid
        $this->postJson('/api/drafts', ['type' => 'sale'])->assertStatus(422);
        $this->postJson('/api/drafts', ['type' => 'loan', 'data' => ['a' => 1]])->assertStatus(422);

        // permission follows the record kind: a moderator may keep purchase and sale drafts (both default permissions)
        $mod = User::factory()->create(['role' => 'moderator', 'shop_id' => $this->shop->id]);
        Sanctum::actingAs($mod);
        $this->getJson('/api/drafts?type=purchase')->assertOk();
        DB::table('role_permissions')->where('shop_id', $this->shop->id)->where('role', 'moderator')
            ->whereIn('permission_id', Permission::where('key', 'sales.create')->pluck('id'))->delete();
        Sanctum::actingAs(User::find($mod->id)); // a fresh instance, so its permissions are read again
        $this->getJson('/api/drafts?type=sale')->assertForbidden();
    }
}
