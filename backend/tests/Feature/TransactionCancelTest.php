<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Supplier $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = User::where('email', 'owner@a.test')->firstOrFail();
        Sanctum::actingAs($this->owner);
        $this->seller = Supplier::create(['name' => 'Seller', 'phone' => '01000000001']);
        $this->assertSame($shop->id, $this->owner->shop_id);
    }

    private function dueSale(): array
    {
        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-'.rand(1000, 9999), 'engine_no' => 'EN-'.rand(1000, 9999), 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->seller->id, 'purchase_date' => today()->subDays(5)->toDateString(), 'price' => 800000, 'paid_amount' => 800000,
        ])->assertCreated()->json('data.vehicle_id');

        return $this->postJson('/api/sales', [
            'vehicle_id' => $id, 'customer_id' => Customer::create(['name' => 'Buyer', 'phone' => '02000000001'])->id, 'sale_date' => today()->subDay()->toDateString(),
            'sale_price' => 1000000, 'payment_type' => 'due', 'down_payment' => 400000,
        ])->assertCreated()->json('data');
    }

    private function collect(array $sale, int $amount): Payment
    {
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => $amount, 'method' => 'cash'])->assertCreated();

        return Payment::where('sale_id', $sale['id'])->where('amount', $amount)->firstOrFail();
    }

    public function test_cancelling_a_customer_payment_restores_the_due_and_keeps_the_row_on_the_statement(): void
    {
        $sale = $this->dueSale();
        $pay = $this->collect($sale, 100000);
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'due_amount' => 500000]);

        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'Entered twice'])->assertOk();

        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'due_amount' => 600000, 'total_paid' => 400000]);
        $this->assertDatabaseHas('payments', ['id' => $pay->id, 'cancel_reason' => 'Entered twice', 'cancelled_by' => $this->owner->id]);
        $this->assertNull(Payment::find($pay->id)); // every normal query skips it
        $this->assertNotNull(Payment::onlyCancelled()->find($pay->id));

        $statement = $this->getJson("/api/reports/person-transactions?side=customer&customer_id={$sale['customer_id']}")->assertOk()
            ->assertJsonPath('data.total_paid', 400000)->assertJsonPath('data.closing_balance', 600000)->json('data.rows');
        $row = collect($statement)->firstWhere('kind', 'cancelled');
        $this->assertSame([0, 0], [$row['charge'], $row['paid']]);
        $this->assertNull($row['tx']);
        $this->assertContains(['Reason', 'Entered twice'], $row['details']);

        // cancelled once: a second cancel finds nothing
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'Again'])->assertNotFound();
    }

    public function test_cancelling_a_supplier_payment_lowers_the_paid_amount_and_stays_listed(): void
    {
        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-SUP', 'engine_no' => 'EN-SUP', 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->seller->id, 'purchase_date' => today()->subDay()->toDateString(), 'price' => 100000, 'paid_amount' => 40000,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/purchases/$id/payments", ['amount' => 10000, 'method' => 'cash', 'paid_at' => today()->toDateString()])->assertCreated();
        $pay = PurchasePayment::where('purchase_id', $id)->firstOrFail();

        $this->postJson("/api/transactions/supplier_payment/{$pay->id}/cancel", ['reason' => 'Wrong supplier'])->assertOk();

        $this->assertDatabaseHas('purchases', ['id' => $id, 'paid_amount' => 40000, 'payment_status' => 'partial']);
        $rows = $this->getJson("/api/reports/person-transactions?side=supplier&supplier_id={$this->seller->id}")->assertOk()->assertJsonPath('data.total_paid', 40000)->json('data.rows');
        $this->assertSame('Cancelled payment (cash): 10,000.00', collect($rows)->firstWhere('kind', 'cancelled')['description']);
        $this->assertSame(1, Purchase::find($id)->payments()->onlyCancelled()->count());
    }

    public function test_a_reason_is_required_and_a_payment_that_cannot_be_deleted_cannot_be_cancelled(): void
    {
        $sale = $this->dueSale();
        $pay = $this->collect($sale, 100000);
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", [])->assertJsonValidationErrors('reason');
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => str_repeat('x', 256)])->assertJsonValidationErrors('reason');

        // taken at the sale: changed with Edit sale, so it is refused and nothing is written
        $down = Payment::where('sale_id', $sale['id'])->where('note', 'Down payment')->firstOrFail();
        $this->postJson("/api/transactions/customer_payment/{$down->id}/cancel", ['reason' => 'Mistake'])->assertStatus(409);
        $this->assertDatabaseHas('payments', ['id' => $down->id, 'cancelled_at' => null]);

        // a cancelled sale's payments are fixed
        $this->postJson("/api/sales/{$sale['id']}/cancel", ['reason' => 'Buyer left'])->assertOk();
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'Late'])->assertStatus(409);
        $this->assertSame('cancelled', Sale::find($sale['id'])->status);
    }

    public function test_cancelling_needs_the_permission_and_stays_inside_the_shop(): void
    {
        $sale = $this->dueSale();
        $pay = $this->collect($sale, 100000);

        auth()->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->owner->shop_id]));
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'No right'])->assertForbidden();
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", [])->assertForbidden(); // refused before the input is even checked

        auth()->forgetGuards();
        $other = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'Owner B', 'email' => 'owner@b.test', 'password' => 'password']);
        Sanctum::actingAs(User::where('shop_id', $other->id)->firstOrFail());
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'Not mine'])->assertNotFound();
        $this->assertDatabaseHas('payments', ['id' => $pay->id, 'cancelled_at' => null]);
    }

    public function test_a_supplier_payment_cannot_be_cancelled_without_permission_or_from_another_shop(): void
    {
        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-X', 'engine_no' => 'EN-X', 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->seller->id, 'purchase_date' => today()->subDay()->toDateString(), 'price' => 100000, 'paid_amount' => 40000,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/purchases/$id/payments", ['amount' => 10000, 'method' => 'cash', 'paid_at' => today()->toDateString()])->assertCreated();
        $pay = PurchasePayment::where('purchase_id', $id)->firstOrFail();

        auth()->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'moderator', 'shop_id' => $this->owner->shop_id]));
        $this->postJson("/api/transactions/supplier_payment/{$pay->id}/cancel", ['reason' => 'No right'])->assertForbidden();

        auth()->forgetGuards();
        $other = app(ShopService::class)->create(['name' => 'Shop B'], ['name' => 'Owner B', 'email' => 'owner@b.test', 'password' => 'password']);
        Sanctum::actingAs(User::where('shop_id', $other->id)->firstOrFail());
        $this->postJson("/api/transactions/supplier_payment/{$pay->id}/cancel", ['reason' => 'Not mine'])->assertNotFound();
        $this->assertDatabaseHas('purchase_payments', ['id' => $pay->id, 'cancelled_at' => null]);

        // a platform admin has no shop, so the shop endpoints are closed to them
        auth()->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'shop_id' => null]));
        $this->postJson("/api/transactions/supplier_payment/{$pay->id}/cancel", ['reason' => 'Platform'])->assertForbidden();
    }

    public function test_cancelling_is_its_own_permission_apart_from_deleting(): void
    {
        $sale = $this->dueSale();
        $pay = $this->collect($sale, 100000);
        $admin = User::factory()->create(['role' => 'admin', 'shop_id' => $this->owner->shop_id]);
        $cancelId = Permission::where('key', 'transactions.cancel')->value('id');
        $row = ['shop_id' => $admin->shop_id, 'role' => 'admin', 'permission_id' => $cancelId];

        // a new shop's Admin holds both rights; take the cancel right away and the Admin may still delete, but not cancel
        $this->assertTrue($admin->hasPermission('transactions.delete'));
        DB::table('role_permissions')->where($row)->delete();
        auth()->forgetGuards();
        Sanctum::actingAs(User::findOrFail($admin->id));
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'No cancel right'])->assertForbidden();
        $this->assertDatabaseHas('payments', ['id' => $pay->id, 'cancelled_at' => null]);

        DB::table('role_permissions')->insert($row);
        auth()->forgetGuards();
        Sanctum::actingAs(User::findOrFail($admin->id)); // permissions are read once per user object
        $this->postJson("/api/transactions/customer_payment/{$pay->id}/cancel", ['reason' => 'Now allowed'])->assertOk();
        $this->assertNotNull(Payment::onlyCancelled()->find($pay->id));
    }

    public function test_a_new_shops_moderator_cannot_cancel_but_the_admin_can(): void
    {
        $this->assertNotContains('transactions.cancel', PermissionCatalog::moderatorDefaults());
        $this->assertContains('transactions.cancel', PermissionCatalog::adminDefaults());
    }

    public function test_the_shop_chooses_between_delete_and_cancel(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.forms.removal', 'delete');

        $this->putJson('/api/settings', ['forms' => ['removal' => 'cancel']])->assertOk()->assertJsonPath('data.forms.removal', 'cancel');
        $this->getJson('/api/settings')->assertJsonPath('data.forms.removal', 'cancel');

        $this->putJson('/api/settings', ['forms' => ['removal' => 'void']])->assertJsonValidationErrors('forms.removal');
    }
}
