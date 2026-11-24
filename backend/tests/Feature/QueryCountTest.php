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

/**
 * N+1 guard for the lists and reports: each one makes the same number of queries whether it shows 2 or 5 of everything.
 * (Lazy loading already throws outside production; this also catches a query written inside a loop.)
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = [
        '/api/sales', '/api/purchases', '/api/vehicles', '/api/customers', '/api/suppliers', '/api/investors',
        '/api/installments', '/api/promises', '/api/investor-sales/dues', '/api/collections/upcoming?bucket=overdue&with_summary=1',
        '/api/collections/summary', '/api/dashboard', '/api/reports/sales', '/api/reports/purchases', '/api/reports/dues',
        '/api/reports/daily', '/api/reports/stock', '/api/reports/investor-sales', '/api/reports/person-summary?side=customer',
        '/api/reports/person-summary?side=supplier', '/api/reports/transactions?side=customer', '/api/reports/transactions?side=supplier',
        '/api/reports/investor-transactions',
    ];

    private int $n = 0;

    private Investor $investor;

    private Supplier $supplier;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->travelTo(Carbon::parse('2026-03-10 10:00:00'));
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs($shop->owner);
        $this->investor = Investor::create(['name' => 'Nasir']);
        $this->supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000009']);
        // every set is with the same customer, supplier and investor, so their own pages grow with the data too
        $this->customer = Customer::create(['name' => 'Buyer', 'phone' => '01700000001']);
    }

    /** One of everything: two vehicles bought and sold (installments with an investor sale, and a due sale), with payments and a charge. */
    private function addSet(): void
    {
        $n = ++$this->n;
        $buy = fn (string $c) => $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $c, 'engine_no' => "EN-$c", 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-01-01', 'price' => 300000, 'paid_amount' => 100000, 'payment_method' => 'cash',
        ])->assertCreated()->json('data');
        $customer = $this->customer;

        $p = $buy("CH-I$n");
        $this->postJson("/api/purchases/{$p['id']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $sale = $this->postJson('/api/sales', [
            'vehicle_id' => $p['vehicle_id'], 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 500000,
            'payment_type' => 'installment', 'down_payment' => 200000, 'installment_count' => 6, 'first_due_date' => '2026-02-05', 'interest_rate' => 10,
        ])->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 60000])->assertCreated();
        $this->postJson("/api/sales/{$sale['id']}/charges", ['purpose' => 'Late fee', 'amount' => 500, 'charged_on' => '2026-03-01'])->assertCreated();
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000,
            'profit_rate' => 12, 'installment_count' => 6, 'frequency' => 'monthly', 'first_due_date' => '2026-02-06',
        ])->assertCreated()->json('data');
        $this->postJson('/api/investor-settlements', ['investor_id' => $this->investor->id, 'investor_sale_id' => $x['id'], 'type' => 'principal_return', 'amount' => 10000, 'date' => '2026-03-01'])->assertCreated();

        $d = $buy("CH-D$n");
        $this->postJson('/api/sales', [
            'vehicle_id' => $d['vehicle_id'], 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 400000,
            'payment_type' => 'due', 'down_payment' => 300000, 'promised_date' => '2026-03-05',
        ])->assertCreated();
    }

    /** @return array<string, int> queries per endpoint */
    private function counts(): array
    {
        $out = [];
        $c = $this->customer->id;
        $s = $this->supplier->id;
        $i = $this->investor->id;
        foreach ([...self::ENDPOINTS,
            "/api/customers/$c/history", "/api/suppliers/$s/history", "/api/investors/$i/portfolio", "/api/investors/$i/vehicle-payments",
            "/api/accounts?side=customer&customer_id=$c", "/api/accounts?side=supplier&supplier_id=$s", '/api/investor-settlements',
            "/api/reports/person-transactions?side=customer&customer_id=$c", "/api/reports/person-transactions?side=supplier&supplier_id=$s",
            "/api/reports/investor-transactions?investor_id=$i", '/api/vehicles/owned?search=Buyer', '/api/vehicle-deletions',
        ] as $url) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $out[$url] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $out;
    }

    public function test_lists_and_reports_do_not_query_once_per_row(): void
    {
        $this->addSet();
        $this->addSet();
        $few = $this->counts();
        $this->addSet();
        $this->addSet();
        $this->addSet();
        $more = $this->counts();

        $grows = array_filter(array_keys($few), fn ($url) => $more[$url] !== $few[$url]);
        $this->assertSame([], array_map(fn ($url) => "$url: {$few[$url]} queries with 2 sets, {$more[$url]} with 5", array_values($grows)));
    }
}
