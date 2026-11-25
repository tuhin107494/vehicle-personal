<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * N+1 guard: every list and report makes the same number of queries whether it shows 2 or 5 of everything, and every
 * record page whether its record has 2 or 5 payments, charges, edits, settlements and calls.
 * (Lazy loading already throws outside production, but not for a list of one or for loadMissing(); this also catches
 * those, and a query written inside a loop.)
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
        '/api/reports/investor-transactions', '/api/bookings', '/api/drafts?type=sale', '/api/advances', '/api/payables', '/api/users',
        '/api/me', '/api/shop', '/api/vehicle-deletions',
    ];

    private const OPEN = ['is_active' => true, 'approved_at' => '2026-03-01 10:00:00'];

    /** Only the super admin can open these. */
    private const ADMIN_ENDPOINTS = ['/api/shops'];

    private int $n = 0;

    private Investor $investor;

    private Supplier $supplier;

    private Customer $customer;

    private User $owner;

    private User $admin;

    /** @var array{sale: int, purchase: int, vehicle: int, investor_sale: int, installment: int} the first set's records, which every set adds to */
    private array $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class); // many writes per set: the rate limit is not what this checks
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->travelTo(Carbon::parse('2026-03-10 10:00:00'));
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);
        Sanctum::actingAs($this->admin);
        // open shops, so each starts the free trial: the shops list and the signed-in user show its subscription
        $this->owner = app(ShopService::class)->create(self::OPEN + ['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password'])->owner;
        Sanctum::actingAs($this->owner);
        $this->investor = Investor::create(['name' => 'Nasir']);
        $this->supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000009']);
        // every set is with the same customer, supplier and investor, so their own pages grow with the data too
        $this->customer = Customer::create(['name' => 'Buyer', 'phone' => '01700000001']);
    }

    /** @return array<string, mixed> a vehicle bought from the shared supplier */
    private function buy(string $chassis): array
    {
        return $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => "EN-$chassis", 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-01-01', 'price' => 300000, 'paid_amount' => 100000, 'payment_method' => 'cash',
        ])->assertCreated()->json('data');
    }

    /** One of everything, and more of everything on the first set's records. */
    private function addSet(): void
    {
        $n = ++$this->n;
        $customer = $this->customer;
        $terms = [
            'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 500000, 'payment_type' => 'installment',
            'down_payment' => 200000, 'installment_count' => 6, 'first_due_date' => '2026-02-05', 'interest_rate' => 10,
        ];

        // an installment sale with an investor sale, a payment, a charge and a settlement; bought with a supplier payment
        $p = $this->buy("CH-I$n");
        $this->postJson("/api/purchases/{$p['id']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $sale = $this->postJson('/api/sales', ['vehicle_id' => $p['vehicle_id']] + $terms)->assertCreated()->json('data');
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 60000])->assertCreated();
        $this->postJson("/api/sales/{$sale['id']}/charges", ['purpose' => 'Late fee', 'amount' => 500, 'charged_on' => '2026-03-01'])->assertCreated();
        $x = $this->postJson("/api/sales/{$sale['id']}/investor-sale", [
            'investor_id' => $this->investor->id, 'date' => '2026-01-06', 'sale_price' => 500000, 'down_payment' => 200000,
            'profit_rate' => 12, 'installment_count' => 6, 'frequency' => 'monthly', 'first_due_date' => '2026-02-10', // a payment to the investor due today
        ])->assertCreated()->json('data');
        $this->postJson('/api/investor-settlements', ['investor_id' => $this->investor->id, 'investor_sale_id' => $x['id'], 'type' => 'principal_return', 'amount' => 10000, 'date' => '2026-03-01'])->assertCreated();

        // a sale the investor finances on its own plan, with an installment due today (the investor's page, portfolio and the dashboard)
        $f = $this->buy("CH-F$n");
        $this->postJson('/api/sales', ['vehicle_id' => $f['vehicle_id'], 'investor_id' => $this->investor->id, 'investor_share_percent' => 50, 'first_due_date' => '2026-02-10'] + $terms)->assertCreated();

        // a due sale
        $d = $this->buy("CH-D$n");
        $this->postJson('/api/sales', [
            'vehicle_id' => $d['vehicle_id'], 'customer_id' => $customer->id, 'sale_date' => '2026-01-05', 'sale_price' => 400000,
            'payment_type' => 'due', 'down_payment' => 300000, 'promised_date' => '2026-03-05',
        ])->assertCreated();

        // an installment sale with a promise to pay falling due today: the dashboard and the promise list show its sale, with its total
        $pp = $this->buy("CH-P$n");
        $promised = $this->postJson('/api/sales', ['vehicle_id' => $pp['vehicle_id']] + $terms)->assertCreated()->json('data');
        $this->postJson("/api/sales/{$promised['id']}/promises", ['promised_date' => '2026-03-10', 'promised_amount' => 20000])->assertCreated();

        // an open booking, a sale draft holding a vehicle and the customer, an advance, a staff user, a deleted vehicle and another shop
        $b = $this->buy("CH-B$n");
        $this->postJson('/api/bookings', ['vehicle_id' => $b['vehicle_id'], 'customer_id' => $customer->id, 'agreed_price' => 450000, 'advance_amount' => 20000])->assertCreated();
        $r = $this->buy("CH-R$n");
        $this->postJson('/api/drafts', ['type' => 'sale', 'label' => "Draft $n", 'data' => ['values' => ['vehicle_id' => (string) $r['vehicle_id'], 'party_id' => (string) $customer->id]]])->assertCreated();
        $this->postJson('/api/advances', ['supplier_id' => $this->supplier->id, 'amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->postJson('/api/users', ['name' => "Staff $n", 'email' => "staff$n@a.test", 'password' => 'password1', 'role' => 'moderator'])->assertCreated();
        $gone = $this->buy("CH-X$n");
        $this->deleteJson("/api/vehicles/{$gone['vehicle_id']}")->assertOk();
        Sanctum::actingAs($this->admin);
        app(ShopService::class)->create(self::OPEN + ['name' => "Shop $n"], ['name' => "Owner $n", 'email' => "owner$n@x.test", 'password' => 'password']);
        Sanctum::actingAs($this->owner);

        // the first set's sale, purchase and investor sale get one more of everything each time
        $this->main ??= [
            'sale' => $sale['id'], 'purchase' => $p['id'], 'vehicle' => $p['vehicle_id'], 'investor_sale' => $x['id'],
            'installment' => Installment::customer()->whereHas('plan', fn ($q) => $q->where('sale_id', $sale['id']))->orderBy('seq_no')->value('id'),
        ];
        $m = $this->main;
        $this->postJson("/api/sales/{$m['sale']}/payments", ['amount' => 1000])->assertCreated();
        $this->postJson("/api/sales/{$m['sale']}/charges", ['purpose' => "Fee $n", 'amount' => 300, 'charged_on' => '2026-03-02'])->assertCreated();
        $this->putJson("/api/sales/{$m['sale']}", ['memo_no' => "M-$n"] + $terms)->assertOk();
        $this->postJson("/api/purchases/{$m['purchase']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->putJson("/api/purchases/{$m['purchase']}", ['notes' => "Note $n"])->assertOk();
        $this->postJson('/api/investor-settlements', ['investor_id' => $this->investor->id, 'investor_sale_id' => $m['investor_sale'], 'type' => 'principal_return', 'amount' => 1000, 'date' => '2026-03-01'])->assertCreated();
        $this->postJson('/api/collections/call', ['type' => 'installment', 'id' => $m['installment'], 'note' => "Call $n"])->assertOk();
    }

    /** @return array<string, int> queries per endpoint */
    private function counts(): array
    {
        $c = $this->customer->id;
        $s = $this->supplier->id;
        $i = $this->investor->id;
        $m = $this->main;
        $owner = [...self::ENDPOINTS,
            "/api/customers/$c", "/api/customers/$c/history", "/api/suppliers/$s", "/api/suppliers/$s/history", "/api/investors/$i",
            "/api/investors/$i/portfolio", "/api/investors/$i/vehicle-payments", "/api/accounts?side=customer&customer_id=$c",
            "/api/accounts?side=supplier&supplier_id=$s", '/api/investor-settlements', "/api/reports/person-transactions?side=customer&customer_id=$c",
            "/api/reports/person-transactions?side=supplier&supplier_id=$s", "/api/reports/investor-transactions?investor_id=$i", '/api/vehicles/owned?search=Buyer',
            // record pages: their payments, charges, edits, settlements and calls grow with every set
            "/api/sales/{$m['sale']}", "/api/purchases/{$m['purchase']}", "/api/purchases/{$m['purchase']}/payments", "/api/investor-sales/{$m['investor_sale']}",
            "/api/vehicles/{$m['vehicle']}", "/api/vehicles/{$m['vehicle']}/history", "/api/collections/call-history?type=installment&id={$m['installment']}",
        ];

        $out = [];
        foreach ([[$this->owner, $owner], [$this->admin, self::ADMIN_ENDPOINTS]] as [$user, $urls]) {
            Sanctum::actingAs($user);
            foreach ($urls as $url) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->getJson($url)->assertOk();
                $out[$url] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
        }
        Sanctum::actingAs($this->owner);

        return $out;
    }

    /** A list with no rows cannot show a query per row: the parts most likely to have one must have rows to count. */
    private function assertTheRowsAreThere(): void
    {
        $i = $this->investor->id;
        foreach ([
            ['/api/dashboard', 'data.promises_due_today'], ['/api/dashboard', 'data.investor_payments_today'],
            ["/api/investors/$i/portfolio", 'data.sales'], ["/api/investors/$i/portfolio", 'data.investor_sales'],
            ['/api/bookings', 'meta.drafts'], ['/api/vehicle-deletions', 'data'], ['/api/me', 'data.shop.subscription'],
        ] as [$url, $path]) {
            $this->assertNotEmpty($this->getJson($url)->json($path), "$url has nothing at $path");
        }
    }

    public function test_lists_reports_and_record_pages_do_not_query_once_per_row(): void
    {
        $this->addSet();
        $this->addSet();
        $few = $this->counts();
        $this->assertTheRowsAreThere();
        $this->addSet();
        $this->addSet();
        $this->addSet();
        $more = $this->counts();

        $grows = array_filter(array_keys($few), fn ($url) => $more[$url] !== $few[$url]);
        $this->assertSame([], array_map(fn ($url) => "$url: {$few[$url]} queries with 2 sets, {$more[$url]} with 5", array_values($grows)));
    }
}
