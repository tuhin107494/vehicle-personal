<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Payment;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Cancelled payments still belong to their sale or purchase; merging keeps the buyer off their own investor sale; a lowered payment unpays its installment. */
class CancelledPaymentAndMergeFixesTest extends TestCase
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

    /** A purchase of 400,000 (nothing paid): returns [vehicle id, purchase id]. */
    private function buy(string $chassis): array
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '0100'.rand(1000, 9999)]);
        $data = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => 'EN-'.$chassis, 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data');

        return [$data['vehicle_id'], $data['id']];
    }

    private function sell(int $vehicleId, Customer $customer, array $extra = []): array
    {
        return $this->postJson('/api/sales', $extra + [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 500000,
            'payment_type' => 'due', 'down_payment' => 400000, 'promised_date' => '2026-02-05',
        ])->assertCreated()->json('data');
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Buyer', 'phone' => '0200'.rand(1000, 9999)]);
    }

    public function test_a_vehicle_with_a_cancelled_payment_can_be_deleted(): void
    {
        [$vehicleId, $purchaseId] = $this->buy('CH-CNL');
        $sale = $this->sell($vehicleId, $this->customer());
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 10000])->assertCreated();
        $pay = Payment::where('sale_id', $sale['id'])->where('amount', 10000)->value('id');
        $this->postJson("/api/transactions/customer_payment/{$pay}/cancel", ['reason' => 'Wrong'])->assertOk();
        $supplierPay = $this->postJson("/api/purchases/{$purchaseId}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated()->json('data.payment.id');
        $this->postJson("/api/transactions/supplier_payment/{$supplierPay}/cancel", ['reason' => 'Wrong'])->assertOk();

        $this->deleteJson("/api/vehicles/{$vehicleId}")->assertOk();

        $this->assertSame(0, Payment::withoutGlobalScopes()->where('sale_id', $sale['id'])->count());
        $this->assertDatabaseMissing('purchase_payments', ['purchase_id' => $purchaseId]);
        $this->assertDatabaseMissing('vehicles', ['id' => $vehicleId]);
    }

    public function test_a_cancelled_supplier_payment_still_blocks_deleting_the_purchase(): void
    {
        [, $purchaseId] = $this->buy('CH-PUR');
        $supplierPay = $this->postJson("/api/purchases/{$purchaseId}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated()->json('data.payment.id');
        $this->postJson("/api/transactions/supplier_payment/{$supplierPay}/cancel", ['reason' => 'Wrong'])->assertOk();

        $this->deleteJson("/api/purchases/{$purchaseId}")->assertStatus(409)->assertJsonPath('message', 'Payments were recorded against this purchase, so it cannot be deleted.');
    }

    public function test_a_charge_whose_payment_was_cancelled_can_be_deleted(): void
    {
        [$vehicleId] = $this->buy('CH-CHG');
        $sale = $this->sell($vehicleId, $customer = $this->customer());
        $charge = $this->postJson("/api/sales/{$sale['id']}/charges", ['purpose' => 'Late fee', 'amount' => 5000, 'charged_on' => '2026-01-10'])->assertCreated()->json('data');
        // the 100,000 balance first, then 5,000 on its own: that one pays the charge
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $customer->id, 'amount' => 100000, 'method' => 'cash'])->assertCreated();
        $this->postJson('/api/accounts', ['side' => 'customer', 'customer_id' => $customer->id, 'amount' => 5000, 'method' => 'cash'])->assertCreated();
        $onCharge = Payment::where('sale_charge_id', $charge['id'])->value('id');
        $this->postJson("/api/transactions/customer_payment/{$onCharge}/cancel", ['reason' => 'Wrong'])->assertOk();

        $this->deleteJson("/api/charges/{$charge['id']}")->assertOk();

        $this->assertDatabaseMissing('sale_charges', ['id' => $charge['id']]);
        $this->assertDatabaseHas('payments', ['id' => $onCharge, 'sale_charge_id' => null]); // still on record, as cancelled
    }

    public function test_merging_does_not_make_the_buyer_their_own_additional_customer_on_an_investor_sale(): void
    {
        [$vehicleId] = $this->buy('CH-MRG');
        $buyer = $this->customer();
        $dup = $this->customer();
        $sale = $this->sell($vehicleId, $buyer, ['payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 12, 'first_due_date' => '2026-02-05', 'interest_rate' => 10]);
        $investor = Investor::create(['name' => 'Nasir', 'phone' => '01500000001']);
        $investorSale = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $investor->id, 'customer_id' => $dup->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000,
            'profit_rate' => 10, 'installment_count' => 12, 'frequency' => 'monthly', 'first_due_date' => '2026-02-06',
        ])->assertCreated()->json('data');

        $this->postJson("/api/customers/{$buyer->id}/merge", ['duplicate_ids' => [$dup->id]])->assertOk();

        $this->assertDatabaseHas('investor_sales', ['id' => $investorSale['id'], 'customer_id' => null]);
    }

    public function test_lowering_a_payment_clears_its_installment_paid_date(): void
    {
        [$vehicleId] = $this->buy('CH-LOW');
        $sale = $this->sell($vehicleId, $this->customer(), ['payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 3, 'first_due_date' => '2026-02-05', 'interest_rate' => 0]);
        $first = Installment::where('plan_id', $sale['plan']['id'])->where('seq_no', 1)->firstOrFail(); // 100,000
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 100000, 'installment_id' => $first->id])->assertCreated();
        $this->assertNotNull($first->fresh()->paid_at);
        $pay = Payment::where('installment_id', $first->id)->value('id');

        $this->putJson("/api/transactions/customer_payment/{$pay}", ['amount' => 60000, 'paid_at' => '2026-01-10', 'method' => 'cash'])->assertOk();

        $first->refresh();
        $this->assertEquals(60000, $first->paid_amount);
        $this->assertNotSame('paid', $first->status);
        $this->assertNull($first->paid_at);
    }
}
