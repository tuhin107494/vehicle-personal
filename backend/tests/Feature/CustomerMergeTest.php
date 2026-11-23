<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** One person entered twice (another phone number): the duplicate's sales and payments move to the main entry. */
class CustomerMergeTest extends TestCase
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

    private function sellTo(Customer $customer, string $chassis): int
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '0100'.rand(1000, 9999)]);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => 'EN-'.$chassis, 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 100000,
        ])->assertCreated()->json('data.vehicle_id');

        return $this->postJson('/api/sales', [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 150000,
            'payment_type' => 'due', 'down_payment' => 50000, 'promised_date' => '2026-02-05',
        ])->assertCreated()->json('data.id');
    }

    public function test_duplicates_are_merged_into_the_main_customer(): void
    {
        $main = Customer::create(['name' => 'Karim', 'phone' => '01711111111']);
        $dup = Customer::create(['name' => 'Karim Mia', 'phone' => '01822222222', 'father_name' => 'Rahim', 'nid' => '123']);
        $mainSale = $this->sellTo($main, 'CH-1');
        $dupSale = $this->sellTo($dup, 'CH-2');
        $this->postJson("/api/sales/{$dupSale}/payments", ['amount' => 10000])->assertCreated();

        $this->postJson("/api/customers/{$main->id}/merge", ['duplicate_ids' => [$dup->id]])->assertOk()
            ->assertJsonPath('data.alt_phone', '01822222222')->assertJsonPath('data.father_name', 'Rahim')->assertJsonPath('data.nid', '123');

        $this->assertSame([$main->id, $main->id], [Sale::find($mainSale)->customer_id, Sale::find($dupSale)->customer_id]);
        $this->assertSame(3, Payment::where('sale_id', $dupSale)->count() + Payment::where('sale_id', $mainSale)->count()); // the down payments and the payment
        $this->assertSoftDeleted('customers', ['id' => $dup->id]);
        // the main customer owes both dues now
        $this->getJson("/api/accounts?side=customer&customer_id={$main->id}")->assertOk()->assertJsonPath('data.total_due', 190000);
    }

    public function test_merge_rules(): void
    {
        $main = Customer::create(['name' => 'Karim', 'phone' => '01711111111']);
        $this->postJson("/api/customers/{$main->id}/merge", ['duplicate_ids' => []])->assertUnprocessable();
        $this->postJson("/api/customers/{$main->id}/merge", ['duplicate_ids' => [$main->id]])->assertStatus(409);
        $this->postJson("/api/customers/{$main->id}/merge", ['duplicate_ids' => [999999]])->assertStatus(409);
    }
}
