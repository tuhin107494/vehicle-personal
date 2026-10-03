<?php

namespace Tests\Feature;

use App\Models\DuePromise;
use App\Models\Installment;
use App\Models\Investor;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Sale;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenancyAndRolesTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $this->superAdmin = User::factory()->create(['role' => 'super_admin']);
    }

    private function makeShop(string $name): array
    {
        $shop = app(ShopService::class)->create(['name' => $name], ['name' => "$name owner", 'email' => strtolower($name).'@t.test', 'password' => 'password']);

        return [$shop, $shop->owner];
    }

    private function as(User $user): void
    {
        auth()->forgetGuards();
        Sanctum::actingAs($user);
    }

    public function test_shops_are_isolated_and_uniques_are_per_shop(): void
    {
        [, $ownerA] = $this->makeShop('A');
        [, $ownerB] = $this->makeShop('B');

        $this->as($ownerA);
        $party = $this->postJson('/api/parties', ['name' => 'X', 'phone' => '01710001234', 'type' => 'customer'])->assertCreated()->json('data');
        $this->postJson('/api/parties', ['name' => 'Dup', 'phone' => '01710001234', 'type' => 'customer'])->assertStatus(422);

        $this->as($ownerB);
        $this->getJson("/api/parties/{$party['id']}")->assertNotFound();
        $this->getJson('/api/parties')->assertJsonPath('meta.total', 0);
        // the same phone is fine in another shop
        $this->postJson('/api/parties', ['name' => 'Y', 'phone' => '01710001234', 'type' => 'customer'])->assertCreated();
        // cannot point at another shop's party
        $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'C1', 'engine_no' => 'E1', 'party_id' => $party['id'], 'purchase_date' => today()->toDateString(), 'price' => 10,
        ])->assertStatus(422)->assertJsonValidationErrors('party_id');
    }

    public function test_super_admin_creates_shop_and_cannot_see_shop_data(): void
    {
        $this->as($this->superAdmin);
        $this->postJson('/api/shops', [
            'name' => 'New Shop', 'owner_name' => 'Owner', 'owner_email' => 'o@new.test', 'owner_password' => 'secret123',
        ])->assertCreated()->assertJsonPath('data.owner.email', 'o@new.test');

        $this->getJson('/api/parties')->assertForbidden();
        $this->getJson('/api/shops')->assertOk()->assertJsonPath('meta.total', 1);

        $this->postJson('/api/login', ['email' => 'o@new.test', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('data.user.role', 'shop_owner');
    }

    public function test_shop_owner_changes_role_permissions_and_it_is_enforced(): void
    {
        [$shop, $owner] = $this->makeShop('A');
        $mod = User::factory()->create(['role' => 'moderator', 'shop_id' => $shop->id]);

        $this->as($mod);
        $this->getJson('/api/reports/stock')->assertForbidden();           // not a moderator default
        $this->postJson('/api/parties', ['name' => 'P', 'phone' => '01710005551', 'type' => 'customer'])->assertCreated();

        $this->as($owner);
        $current = $this->getJson('/api/roles')->assertOk()->json('data.1');
        $this->assertSame('moderator', $current['role']);
        $this->assertNotContains('reports.view', $current['permissions']);

        $keys = array_values(array_diff([...$current['permissions'], 'reports.view'], ['parties.create']));
        $this->putJson('/api/roles/moderator/permissions', ['permissions' => $keys])->assertOk();

        $this->as($mod->fresh());
        $this->getJson('/api/reports/stock')->assertOk();                   // added
        $this->postJson('/api/parties', ['name' => 'Q', 'phone' => '01710005552', 'type' => 'customer'])->assertForbidden(); // removed

        $this->as($owner);
        $this->putJson('/api/roles/shop_owner/permissions', ['permissions' => []])->assertNotFound();
    }

    public function test_only_super_admin_manages_permission_catalog(): void
    {
        [, $owner] = $this->makeShop('A');

        $this->as($owner);
        $this->getJson('/api/permissions')->assertOk();
        $this->postJson('/api/permissions', ['key' => 'reports.export', 'name' => 'Export', 'group' => 'reports'])->assertForbidden();

        $this->as($this->superAdmin);
        $this->postJson('/api/permissions', ['key' => 'reports.export', 'name' => 'Export reports', 'group' => 'reports'])->assertCreated();
        $id = Permission::where('key', 'reports.export')->value('id');
        $this->putJson("/api/permissions/$id", ['name' => 'Export data', 'group' => 'reports'])->assertOk();
        $this->putJson("/api/permissions/$id", ['key' => 'x.y', 'name' => 'n', 'group' => 'g'])->assertStatus(422);
        $this->deleteJson("/api/permissions/$id")->assertOk();
    }

    public function test_vehicle_categories_managed_by_super_admin_and_validated(): void
    {
        [, $owner] = $this->makeShop('A');

        $this->as($owner);
        $this->getJson('/api/vehicle-categories')->assertOk()->assertJsonCount(4, 'data');
        $this->postJson('/api/purchases', [
            'vehicle_type' => 'truck', 'chassis_no' => 'T1', 'engine_no' => 'T1', 'party_id' => 1, 'purchase_date' => today()->toDateString(), 'price' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('vehicle_type');

        $this->as($this->superAdmin);
        $id = $this->postJson('/api/vehicle-categories', ['name' => 'Tempo Truck'])->assertCreated()->assertJsonPath('data.key', 'tempo_truck')->json('data.id');
        $this->postJson('/api/vehicle-categories', ['name' => 'Tempo Truck'])->assertStatus(422);
        $this->putJson("/api/vehicle-categories/$id", ['name' => 'Tempo', 'default_brand' => 'Tata', 'default_color' => 'Blue', 'default_cc' => 1500])->assertOk()->assertJsonPath('data.default_cc', 1500);
        $this->getJson('/api/vehicle-categories')->assertJsonPath('data.1.default_brand', 'Bajaj')->assertJsonPath('data.1.default_color', 'Green')->assertJsonPath('data.1.default_cc', 200);
        $this->deleteJson("/api/vehicle-categories/$id")->assertOk();

        // a category in use cannot be deleted
        $this->as($owner);
        $seller = Party::create(['name' => 'S', 'phone' => '7', 'type' => 'supplier']);
        $this->postJson('/api/purchases', [
            'vehicle_type' => 'cng', 'chassis_no' => 'V1', 'engine_no' => 'V1', 'party_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 1,
        ])->assertCreated();
        $this->as($this->superAdmin);
        $cng = VehicleCategory::where('key', 'cng')->value('id');
        $this->deleteJson("/api/vehicle-categories/$cng")->assertStatus(409);
    }

    public function test_shop_adds_its_own_vehicle_type_only_that_shop_sees(): void
    {
        [$shopA, $ownerA] = $this->makeShop('A');
        [, $ownerB] = $this->makeShop('B');
        $mod = User::factory()->create(['role' => 'moderator', 'shop_id' => $shopA->id]);

        $this->as($ownerA);
        $id = $this->postJson('/api/vehicle-categories', ['name' => 'Pickup', 'default_brand' => 'Toyota', 'default_model' => 'Hilux', 'default_cc' => 2400, 'purchase_price' => 2500000])
            ->assertCreated()->assertJsonPath('data.default_model', 'Hilux')->assertJsonPath('data.purchase_price', 2500000)->assertJsonPath('data.key', 'pickup')->assertJsonPath('data.shop_id', $shopA->id)->json('data.id');
        $this->getJson('/api/settings')->assertJsonPath('data.purchase.prices.pickup', 2500000);
        $this->postJson('/api/vehicle-categories', ['name' => 'CNG'])->assertStatus(422);                  // clashes with a platform type
        $this->getJson('/api/vehicle-categories')->assertJsonCount(5, 'data');

        // usable on a purchase by anyone in the shop
        $this->as($mod);
        $seller = Party::create(['name' => 'S', 'phone' => '5', 'type' => 'supplier']);
        $this->postJson('/api/purchases', ['vehicle_type' => 'pickup', 'chassis_no' => 'PK1', 'engine_no' => 'PK1', 'party_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 1])->assertCreated();

        // other shop neither sees nor can use it, and the platform list stays intact
        $this->as($ownerB);
        $this->getJson('/api/vehicle-categories')->assertJsonCount(4, 'data');
        $this->putJson("/api/vehicle-categories/$id", ['name' => 'Hijack'])->assertNotFound();
        $this->postJson('/api/vehicle-categories', ['name' => 'Pickup'])->assertCreated();               // same name is fine in another shop
        // a shop cannot change a platform type
        $cng = VehicleCategory::withoutGlobalScopes()->where('key', 'cng')->value('id');
        $this->putJson("/api/vehicle-categories/$cng", ['name' => 'X'])->assertForbidden();

        // in-use types cannot be deleted; unused ones can
        $this->as($ownerA);
        $this->deleteJson("/api/vehicle-categories/$id")->assertStatus(409);

        // the super admin still sees only platform types
        $this->as($this->superAdmin);
        $this->getJson('/api/vehicle-categories')->assertJsonCount(4, 'data');
    }

    public function test_name_and_mobile_number_are_required_with_clear_messages(): void
    {
        [, $owner] = $this->makeShop('A');
        $this->as($owner);

        $this->postJson('/api/parties', ['type' => 'customer'])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Name is required.')->assertJsonPath('errors.phone.0', 'Mobile number is required.');
        $this->postJson('/api/parties', ['name' => 'Bad', 'phone' => 'abc', 'type' => 'supplier'])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'Enter a valid mobile number (7 to 20 digits).');
        $this->postJson('/api/parties', ['name' => 'Bad', 'phone' => '01710009999', 'alt_phone' => '12', 'type' => 'customer'])->assertStatus(422)
            ->assertJsonValidationErrors('alt_phone');
        $this->postJson('/api/parties', ['name' => 'Ok', 'phone' => '+880 1710-009999', 'type' => 'customer'])->assertCreated();

        $this->postJson('/api/investors', ['name' => 'No phone'])->assertStatus(422)->assertJsonPath('errors.phone.0', 'Mobile number is required.');
        $this->postJson('/api/investors', ['name' => 'Inv', 'phone' => '01810001111'])->assertCreated();
    }

    public function test_father_name_is_kept_for_customers_only(): void
    {
        [, $owner] = $this->makeShop('A');
        $this->as($owner);

        $this->postJson('/api/parties', ['name' => 'Rahim', 'father_name' => 'Karim Uddin', 'phone' => '01710000001', 'type' => 'customer'])
            ->assertCreated()->assertJsonPath('data.father_name', 'Karim Uddin');
        $id = $this->postJson('/api/parties', ['name' => 'Parts Ltd', 'father_name' => 'Ignored', 'phone' => '01710000002', 'type' => 'supplier'])
            ->assertCreated()->assertJsonPath('data.father_name', null)->json('data.id');

        // a supplier cannot gain one by editing either
        $this->putJson("/api/parties/$id", ['name' => 'Parts Ltd', 'father_name' => 'Nope', 'phone' => '01710000002', 'type' => 'supplier'])->assertOk()->assertJsonPath('data.father_name', null);
        $this->getJson('/api/parties?search=Karim%20Uddin')->assertJsonPath('meta.total', 1);
    }

    public function test_bank_info_is_kept_for_suppliers_only(): void
    {
        [, $owner] = $this->makeShop('A');
        $this->as($owner);

        $this->postJson('/api/parties', ['name' => 'Parts Ltd', 'phone' => '01710000012', 'type' => 'supplier', 'bank_name' => 'City Bank', 'bank_account_no' => '123', 'bank_info' => 'bKash 017'])
            ->assertCreated()->assertJsonPath('data.bank_name', 'City Bank')->assertJsonPath('data.bank_account_no', '123')->assertJsonPath('data.bank_info', 'bKash 017');
        $this->postJson('/api/parties', ['name' => 'Rahim', 'phone' => '01710000013', 'type' => 'customer', 'bank_name' => 'Ignored', 'bank_info' => 'Ignored'])
            ->assertCreated()->assertJsonPath('data.bank_info', null)->assertJsonPath('data.bank_name', null);
    }

    public function test_shop_settings_are_per_shop_and_permission_protected(): void
    {
        [$shopA, $ownerA] = $this->makeShop('A');
        [, $ownerB] = $this->makeShop('B');
        $mod = User::factory()->create(['role' => 'moderator', 'shop_id' => $shopA->id]);

        $this->as($ownerA);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.purchase.vehicle_type', null)->assertJsonPath('data.sale.vehicle_type', null);
        $this->putJson('/api/settings', ['purchase' => ['vehicle_type' => 'cng'], 'sale' => ['vehicle_type' => 'motorcycle']])
            ->assertOk()->assertJsonPath('data.purchase.vehicle_type', 'cng')->assertJsonPath('data.sale.vehicle_type', 'motorcycle');
        $this->putJson('/api/settings', ['sale' => ['vehicle_type' => 'spaceship']])->assertStatus(422);
        $this->putJson('/api/settings', ['sale' => ['vehicle_type' => null]])->assertOk()->assertJsonPath('data.sale.vehicle_type', null);
        $this->putJson('/api/settings', ['purchase' => ['prices' => ['cng' => 250000]], 'sale' => ['prices' => ['cng' => 300000]]])
            ->assertOk()->assertJsonPath('data.purchase.prices.cng', 250000)->assertJsonPath('data.sale.prices.cng', 300000);
        $this->putJson('/api/settings', ['purchase' => ['prices' => ['spaceship' => 100]]])->assertStatus(422);
        $this->putJson('/api/settings', ['purchase' => ['prices' => ['cng' => null]]])->assertOk()->assertJsonMissingPath('data.purchase.prices.cng');
        $this->putJson('/api/settings', ['type_defaults' => ['cng' => ['brand' => 'TVS', 'color' => 'Red', 'cc' => 180, 'year' => 2024, 'fuel_type' => 'CNG', 'mileage' => 0]]])
            ->assertOk()->assertJsonPath('data.type_defaults.cng.brand', 'TVS')->assertJsonPath('data.type_defaults.cng.cc', 180)->assertJsonPath('data.type_defaults.cng.year', 2024)->assertJsonPath('data.type_defaults.cng.fuel_type', 'CNG');
        $this->putJson('/api/settings', ['type_defaults' => ['cng' => ['year' => 1999]]])->assertStatus(422);
        $this->putJson('/api/settings', ['type_defaults' => ['spaceship' => ['brand' => 'X']]])->assertStatus(422);

        $this->as($mod);
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.type_defaults.cng.brand', 'TVS');   // anyone in the shop can read
        $this->putJson('/api/settings', ['type_defaults' => ['cng' => ['brand' => 'Hero']]])->assertForbidden(); // moderators cannot change

        $this->as($ownerB);
        $this->getJson('/api/settings')->assertJsonPath('data.purchase.vehicle_type', null)->assertJsonMissingPath('data.type_defaults.cng');                        // other shop untouched
    }

    public function test_shop_owner_manages_only_own_users(): void
    {
        [$shopA, $ownerA] = $this->makeShop('A');
        [, $ownerB] = $this->makeShop('B');

        $this->as($ownerA);
        $id = $this->postJson('/api/users', ['name' => 'Ad', 'email' => 'ad@t.test', 'password' => 'password1', 'role' => 'admin'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/users', ['name' => 'Bad', 'email' => 'bad@t.test', 'password' => 'password1', 'role' => 'super_admin'])->assertStatus(422);
        $this->assertSame($shopA->id, User::find($id)->shop_id);

        $this->putJson("/api/users/$id", ['name' => 'Ad', 'email' => 'ad@t.test', 'role' => 'moderator', 'is_active' => false])->assertOk();
        $this->postJson('/api/login', ['email' => 'ad@t.test', 'password' => 'password1'])->assertStatus(422);

        $this->as($ownerB);
        $this->putJson("/api/users/$id", ['name' => 'Hijack', 'email' => 'ad@t.test', 'role' => 'admin'])->assertNotFound();

        $this->as($this->superAdmin);
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_disabled_shop_blocks_login(): void
    {
        [$shop] = $this->makeShop('A');
        $this->as($this->superAdmin);
        $this->putJson("/api/shops/{$shop->id}", ['name' => 'A', 'is_active' => false])->assertOk();
        $this->postJson('/api/login', ['email' => 'a@t.test', 'password' => 'password'])->assertStatus(422);
    }

    public function test_due_lists_show_overdue_by_months_and_days_and_upcoming_windows(): void
    {
        [, $owner] = $this->makeShop('A');
        $this->as($owner);

        $seller = Party::create(['name' => 'S', 'phone' => '01710009991', 'type' => 'supplier']);
        $buyer = Party::create(['name' => 'Buyer', 'phone' => '01710009992', 'alt_phone' => '01810009992', 'type' => 'customer']);
        $investor = Investor::create(['name' => 'Inv', 'phone' => '01710009993']);
        $buy = fn (string $ch) => $this->postJson('/api/purchases', [
            'vehicle_type' => 'cng', 'chassis_no' => $ch, 'engine_no' => "E-$ch", 'party_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 100,
        ])->json('data.vehicle_id');

        // an installment sale: weekly, first due tomorrow, so the 2nd falls on day 8
        $this->postJson('/api/sales', [
            'vehicle_id' => $buy('L-1'), 'party_id' => $buyer->id, 'sale_date' => today()->subDay()->toDateString(), 'sale_price' => 1000,
            'payment_type' => 'installment', 'down_payment' => 0, 'investor_id' => $investor->id,
            'installment_count' => 2, 'first_due_date' => today()->addDay()->toDateString(), 'frequency' => 'weekly',
        ])->assertCreated();

        // a normal due sale with a pay date in 5 days
        $this->postJson('/api/sales', [
            'vehicle_id' => $buy('L-2'), 'party_id' => $buyer->id, 'sale_date' => today()->subDay()->toDateString(), 'sale_price' => 400,
            'payment_type' => 'due', 'down_payment' => 100, 'promised_date' => today()->addDays(5)->toDateString(),
        ])->assertCreated();

        $list = fn (string $bucket, string $kind = '') => $this->getJson("/api/collections/upcoming?bucket=$bucket".($kind ? "&kind=$kind" : ''))->assertOk()->json('data');

        $this->assertCount(1, $list('d3'));                           // installment #1 is tomorrow
        $this->assertCount(2, $list('d7'));                           // plus the normal due in 5 days
        $this->assertCount(3, $list('d10'));                          // plus installment #2 on day 8
        $this->assertCount(2, $list('d10', 'installment'));
        $this->assertCount(1, $list('d10', 'promise'));
        $this->assertSame('Normal due', $list('d10', 'promise')[0]['label']);
        $this->assertSame(300, (int) $list('d10', 'promise')[0]['amount']);
        $this->assertCount(0, $list('overdue'));
        $first = $list('d3')[0];
        $this->assertSame('01810009992', $first['party']['alt_phone']);
        $this->assertSame(1300, (int) $first['party_total_due']);      // 1,000 installments + 300 normal due
        $this->getJson('/api/collections/upcoming?bucket=nope')->assertStatus(422);

        // age them: installments due 2 months and 3 days ago, the normal due promised 4 days ago
        $due = today()->subMonths(2)->subDays(3);
        Installment::query()->update(['due_date' => $due->toDateString()]);
        DuePromise::query()->update(['promised_date' => today()->subDays(4)->toDateString()]);

        $overdue = $list('overdue');
        $this->assertCount(3, $overdue);
        $installment = collect($overdue)->firstWhere('type', 'installment');
        $gap = $due->diff(today());                                   // calendar months and the days left over
        $this->assertSame($gap->y * 12 + $gap->m, $installment['overdue_by']['months']);
        $this->assertSame($gap->d, $installment['overdue_by']['days']);
        $this->assertGreaterThanOrEqual(2, $installment['overdue_by']['months']);
        $this->assertSame((int) $due->diffInDays(today()), $installment['overdue_by']['total_days']);
        $normal = collect($overdue)->firstWhere('type', 'promise');
        $this->assertSame(['months' => 0, 'days' => 4, 'total_days' => 4], $normal['overdue_by']);

        $summary = $this->getJson('/api/collections/summary')->assertOk()->json('data');
        $this->assertSame(['overdue', 'd3', 'd7', 'd10'], array_column($summary['buckets'], 'key'));
        $this->assertSame(3, $summary['buckets'][0]['count']);
        $this->assertSame(2, $summary['buckets'][0]['kinds']['installment']['count']);
        $this->assertSame(1, $summary['buckets'][0]['kinds']['promise']['count']);
        $this->assertEquals(1300, $summary['current_due']);

        // the status shown is worked out from the date, not left as "pending"
        $sale = Sale::where('payment_type', 'installment')->first();
        $this->getJson("/api/sales/{$sale->id}")->assertJsonPath('data.plan.installments.0.status', 'overdue');
    }
}
