<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Investor;
use App\Models\Supplier;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Plans saved before rates were yearly hold a rate for the whole plan: the migration turns it into a yearly rate from the amount charged. */
class YearlyRateMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->travelTo(Carbon::parse('2026-01-10 10:00:00'));
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs($shop->owner);
    }

    public function test_the_investor_installments_backfill_matches_the_service(): void
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000002']);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-FILL', 'engine_no' => 'EN-FILL', 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');
        $sale = $this->postJson('/api/sales', [
            'vehicle_id' => $vehicleId, 'customer_id' => Customer::create(['name' => 'Buyer', 'phone' => '02000000002'])->id, 'sale_date' => '2025-06-05',
            'sale_price' => 500000, 'payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 12, 'first_due_date' => '2025-07-05', 'interest_rate' => 10,
        ])->assertCreated()->json('data');
        $investor = Investor::create(['name' => 'Nasir']);
        // 12 installments from mid 2025 (some already overdue), and 50,000 paid back: some paid, one partial
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $investor->id, 'date' => '2025-06-06', 'sale_price' => 500000, 'down_payment' => 200000,
            'profit_rate' => 13, 'installment_count' => 12, 'frequency' => 'monthly', 'first_due_date' => '2025-07-06',
        ])->assertCreated()->json('data');
        $this->postJson('/api/investor-settlements', ['investor_id' => $investor->id, 'investor_sale_id' => $x['id'], 'type' => 'principal_return', 'amount' => 50000, 'date' => '2026-01-09'])->assertCreated();

        $columns = ['seq_no', 'due_date', 'amount', 'paid_amount', 'status', 'paid_at'];
        $fromService = DB::table('installments')->where('investor_sale_id', $x['id'])->orderBy('seq_no')->get($columns)->map(fn ($r) => (array) $r)->all();
        DB::table('installments')->where('investor_sale_id', $x['id'])->delete();

        $migration = require database_path('migrations/2026_11_21_110000_add_investor_sale_id_to_installments_table.php');
        (new \ReflectionMethod($migration, 'fill'))->invoke($migration, DB::table('investor_sales')->find($x['id']));

        $fromMigration = DB::table('installments')->where('investor_sale_id', $x['id'])->orderBy('seq_no')->get($columns)->map(fn ($r) => (array) $r)->all();
        $this->assertCount(12, $fromMigration);
        $this->assertEquals($fromService, $fromMigration);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_24_100000_store_interest_and_profit_rates_per_year.php');
    }

    public function test_old_whole_plan_rates_become_yearly_rates_and_amounts_stay(): void
    {
        $supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000001']);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-RATE', 'engine_no' => 'EN-RATE', 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $supplier->id, 'purchase_date' => '2026-01-01', 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '02000000001']);

        // 600,000 with 200,000 down and 50,000 promised: 350,000 in 24 installments with 70,000 interest
        $sale = $this->postJson('/api/sales', [
            'vehicle_id' => $vehicleId, 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 600000,
            'payment_type' => 'installment', 'down_payment' => 200000, 'promise_amount' => 50000, 'promised_date' => '2026-03-01',
            'installment_count' => 24, 'first_due_date' => '2026-02-05', 'interest_amount' => 70000,
        ])->assertCreated()->json('data');
        $investor = Investor::create(['name' => 'Nasir']);
        // the investor's 350,000 over 24 months with 84,000 profit
        $investorSale = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $investor->id, 'date' => '2026-01-06', 'sale_price' => 600000, 'down_payment' => 250000,
            'profit_mode' => 'amount', 'profit_amount' => 84000, 'installment_count' => 24, 'frequency' => 'monthly', 'first_due_date' => '2026-02-06',
        ])->assertCreated()->json('data');

        // as an old plan held them: the rate for the whole plan (70,000 of 350,000 = 20%; 84,000 of 350,000 = 24%)
        DB::table('installment_plans')->where('id', $sale['plan']['id'])->update(['interest_rate' => 20]);
        DB::table('investor_sales')->where('id', $investorSale['id'])->update(['profit_rate' => 24]);

        $this->migration()->up();

        // two years: 10% and 12% a year; the amounts are as they were
        $this->assertDatabaseHas('installment_plans', ['id' => $sale['plan']['id'], 'interest_rate' => 10, 'interest_amount' => 70000]);
        $this->assertDatabaseHas('investor_sales', ['id' => $investorSale['id'], 'profit_rate' => 12, 'profit_amount' => 84000]);

        // running it again changes nothing; down() gives back the whole-plan rate
        $this->migration()->up();
        $this->assertDatabaseHas('installment_plans', ['id' => $sale['plan']['id'], 'interest_rate' => 10]);
        $this->migration()->down();
        $this->assertDatabaseHas('installment_plans', ['id' => $sale['plan']['id'], 'interest_rate' => 20]);
        $this->assertDatabaseHas('investor_sales', ['id' => $investorSale['id'], 'profit_rate' => 24]);
    }
}
