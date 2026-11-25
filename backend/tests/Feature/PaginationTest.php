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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Every paged list: 1 to 100 rows a page (15 if not asked), and its pages split the rows with nothing repeated or missed. */
class PaginationTest extends TestCase
{
    use RefreshDatabase;

    /** The shop's paged lists (the super admin's shop list is checked on its own). */
    private const LISTS = [
        '/api/sales', '/api/purchases', '/api/vehicles', '/api/customers', '/api/suppliers', '/api/investors', '/api/investors?with_due_count=1',
        '/api/investor-settlements', '/api/bookings', '/api/promises', '/api/installments',
    ];

    private User $owner;

    private User $admin;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-03-10 10:00:00'));
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'role' => 'super_admin']);
        Sanctum::actingAs($this->admin);
        $this->owner = app(ShopService::class)->create(['name' => 'Shop A', 'is_active' => true, 'approved_at' => now()], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password'])->owner;
        Sanctum::actingAs($this->owner);
        $this->supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000009']);
    }

    private function sell(string $chassis, array $terms): array
    {
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => "EN-$chassis", 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->supplier->id, 'purchase_date' => '2026-01-01', 'price' => 300000,
        ])->assertCreated()->json('data.vehicle_id');

        return $this->postJson('/api/sales', ['vehicle_id' => $vehicleId, 'sale_date' => '2026-01-05', 'sale_price' => 400000] + $terms)->assertCreated()->json('data');
    }

    /** @return list<int> the ids on every page of the list, in order */
    private function walk(string $url, int $perPage, int $expectedTotal): array
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        $last = (int) ceil($expectedTotal / $perPage);
        $ids = [];
        for ($page = 1; $page <= $last; $page++) {
            $res = $this->getJson("$url{$sep}per_page=$perPage&page=$page")->assertOk()
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', $last)
                ->assertJsonPath('meta.per_page', $perPage)->assertJsonPath('meta.total', $expectedTotal);
            $ids = [...$ids, ...array_column($res->json('data'), 'id')];
        }

        return $ids;
    }

    public function test_every_list_takes_1_to_100_rows_a_page(): void
    {
        $bad = [
            '101' => 'Rows per page cannot be more than 100.', '1000' => 'Rows per page cannot be more than 100.',
            '0' => 'Rows per page must be at least 1.', '-5' => 'Rows per page must be at least 1.',
            'abc' => 'Rows per page must be a whole number.', '2.5' => 'Rows per page must be a whole number.',
        ];

        foreach ([...array_map(fn ($u) => [$u, $this->owner], self::LISTS), ['/api/shops', $this->admin]] as [$url, $user]) {
            Sanctum::actingAs($user);
            $sep = str_contains($url, '?') ? '&' : '?';
            $this->getJson($url)->assertOk()->assertJsonPath('meta.per_page', 15);
            $this->getJson("$url{$sep}per_page=100")->assertOk()->assertJsonPath('meta.per_page', 100);
            foreach ($bad as $value => $message) {
                $this->getJson("$url{$sep}per_page=$value")->assertStatus(422)->assertJsonPath('errors.per_page.0', $message);
            }
        }
    }

    public function test_pages_split_a_list_with_nothing_repeated_or_missed(): void
    {
        $customers = collect(range(1, 7))->map(fn ($n) => Customer::create(['name' => "Customer $n", 'phone' => "0170000000$n"])->id);
        $investors = collect(range(1, 7))->map(fn ($n) => Investor::create(['name' => "Investor $n", 'phone' => "0180000000$n"])->id);

        foreach (['/api/customers' => $customers, '/api/investors' => $investors, '/api/investors?with_due_count=1' => $investors] as $url => $all) {
            $ids = $this->walk($url, 3, 7);
            $this->assertCount(7, $ids, "$url repeats a row");
            $this->assertEqualsCanonicalizing($all->all(), $ids, "$url misses a row");

            // past the last page: no rows, the same totals; a page number that is not one is page 1
            $sep = str_contains($url, '?') ? '&' : '?';
            $this->getJson("$url{$sep}per_page=3&page=9")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 7);
            foreach (['0', '-2', 'abc'] as $page) {
                $this->getJson("$url{$sep}per_page=3&page=$page")->assertOk()->assertJsonPath('meta.current_page', 1)->assertJsonCount(3, 'data');
            }
        }
    }

    public function test_rows_with_the_same_date_still_page_in_one_fixed_order(): void
    {
        $customer = Customer::create(['name' => 'Buyer', 'phone' => '01700000001']);
        // five due sales promised for the same day, and three installment sales whose installments share their dates and numbers
        foreach (range(1, 5) as $n) {
            $this->sell("CH-D$n", ['customer_id' => $customer->id, 'payment_type' => 'due', 'down_payment' => 300000, 'promised_date' => '2026-04-01']);
        }
        foreach (range(1, 3) as $n) {
            $this->sell("CH-I$n", ['customer_id' => $customer->id, 'payment_type' => 'installment', 'down_payment' => 100000, 'installment_count' => 4, 'first_due_date' => '2026-02-05', 'interest_rate' => 10]);
        }

        $promises = $this->walk('/api/promises', 2, 5);
        $this->assertSame(array_values(array_unique($promises)), $promises);
        $this->assertCount(5, $promises);

        $installments = $this->walk('/api/installments', 5, 12);
        $this->assertSame(Installment::customer()->orderBy('due_date')->orderBy('seq_no')->orderBy('id')->pluck('id')->all(), $installments);
    }
}
