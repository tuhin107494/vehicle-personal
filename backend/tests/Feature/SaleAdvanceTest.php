<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'shop_id' => $shop->id]));

        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-1', 'engine_no' => 'EN-1', 'brand' => 'Honda', 'model' => 'CBR',
            'supplier_id' => Supplier::create(['name' => 'Seller', 'phone' => '01001234567'])->id, 'purchase_date' => today()->toDateString(), 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');

        $this->vehicle = Vehicle::findOrFail($id);
        $this->customer = Customer::create(['name' => 'Buyer', 'phone' => '02001234567']);
    }

    private function sell(array $over): TestResponse
    {
        return $this->postJson('/api/sales', $over + [
            'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'sale_date' => today()->toDateString(), 'sale_price' => 500000,
        ]);
    }

    public function test_a_cash_sale_keeps_an_earlier_advance_as_its_own_payment(): void
    {
        $day = today()->subDays(3)->toDateString();

        $this->sell(['payment_type' => 'cash', 'advance_amount' => 10000, 'advance_date' => $day])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertEquals(0, $sale->due_amount);
        $this->assertCount(2, $sale->payments);
        $advance = $sale->payments->firstWhere('note', 'Advance');
        $this->assertEquals(10000, $advance->amount);
        $this->assertSame($day, $advance->paid_at->toDateString());

        // the sale page shows the advance and its day
        $this->getJson("/api/sales/{$sale->id}")->assertOk()->assertJsonPath('data.advance.amount', 10000)->assertJsonPath('data.advance.date', $day);

        // cash is counted once: the advance on its own day, the rest on the sale day
        $then = $this->getJson("/api/reports/daily?date=$day")->json('data');
        $this->assertEquals(10000, $then['collections']['total']);
        $now = $this->getJson('/api/reports/daily?date='.today()->toDateString())->json('data');
        $this->assertEquals(490000, $now['sales']['received']);
        $this->assertEquals(490000, $now['cash']['in']);
    }

    public function test_a_due_sale_counts_the_advance_in_the_down_payment(): void
    {
        $this->sell(['payment_type' => 'due', 'advance_amount' => 10000, 'down_payment' => 410000])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertEquals(90000, $sale->due_amount);
        $this->assertEquals(410000, $sale->payments()->sum('amount'));
    }

    public function test_the_down_payment_must_include_the_advance_and_the_advance_must_be_less_than_the_price(): void
    {
        $this->sell(['payment_type' => 'due', 'advance_amount' => 10000, 'down_payment' => 5000])->assertStatus(422)->assertJsonValidationErrors('down_payment');
        $this->sell(['payment_type' => 'cash', 'advance_amount' => 500000])->assertStatus(422)->assertJsonValidationErrors('advance_amount');
        $this->sell(['payment_type' => 'cash', 'advance_amount' => 10000, 'advance_date' => today()->addDay()->toDateString()])->assertStatus(422);
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
    }

    public function test_a_sale_without_an_advance_is_unchanged(): void
    {
        $this->sell(['payment_type' => 'cash'])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertCount(1, $sale->payments);
        $this->getJson("/api/sales/{$sale->id}")->assertJsonPath('data.advance', null);
    }
}
