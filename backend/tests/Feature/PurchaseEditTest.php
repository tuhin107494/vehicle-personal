<?php

namespace Tests\Feature;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseEditTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'shop_id' => $shop->id]));
        $this->seller = Supplier::create(['name' => 'Seller', 'phone' => '01000000001']);
    }

    private function buy(array $extra = []): int
    {
        return $this->postJson('/api/purchases', $extra + [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-'.rand(1000, 9999), 'engine_no' => 'EN-'.rand(1000, 9999), 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->seller->id, 'purchase_date' => today()->toDateString(), 'price' => 100000, 'paid_amount' => 40000,
        ])->assertCreated()->json('data.id');
    }

    public function test_price_and_paid_amount_change_and_the_edit_is_kept(): void
    {
        $id = $this->buy();

        $res = $this->putJson("/api/purchases/$id", ['price' => 120000, 'paid_amount' => 50000])->assertOk()
            ->assertJsonPath('data.price', 120000)->assertJsonPath('data.paid_amount', 50000)->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.has_payments', false);

        $changes = collect($res->json('data.edits.0.changes'))->keyBy('field');
        $this->assertSame(['100000.00', '120000.00'], [$changes['Price']['from'], $changes['Price']['to']]);
        $this->assertSame(['40000.00', '50000.00'], [$changes['Paid amount']['from'], $changes['Paid amount']['to']]);

        // paying it all marks it paid; saving with nothing changed adds no history row
        $this->putJson("/api/purchases/$id", ['paid_amount' => 120000])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->putJson("/api/purchases/$id", ['paid_amount' => 120000])->assertOk();
        $this->assertSame(2, Purchase::find($id)->edits()->count());
    }

    public function test_paid_cannot_exceed_the_price_and_price_cannot_drop_below_what_was_paid(): void
    {
        $id = $this->buy();
        $this->putJson("/api/purchases/$id", ['paid_amount' => 100001])->assertStatus(409);
        $this->putJson("/api/purchases/$id", ['price' => 30000])->assertStatus(409);
        $this->assertSame(0, Purchase::find($id)->edits()->count());
    }

    public function test_paid_amount_is_locked_once_payments_exist_but_price_still_changes(): void
    {
        $id = $this->buy();
        $this->postJson("/api/purchases/$id/payments", ['amount' => 10000, 'method' => 'cash', 'paid_at' => today()->toDateString()])->assertCreated();

        $this->putJson("/api/purchases/$id", ['paid_amount' => 90000])->assertStatus(409);
        $this->putJson("/api/purchases/$id", ['price' => 110000])->assertOk()->assertJsonPath('data.has_payments', true);
    }
}
