<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Investor;
use App\Models\InvestorSettlement;
use App\Models\Purchase;
use App\Models\PurchasePayment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Settlements the system made, purchase paid totals, password resets and a booked sale's buyer. */
class RealIssuesFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        $this->owner = $shop->owner;
        Sanctum::actingAs($this->owner);
        $this->supplier = Supplier::create(['name' => 'Seller', 'phone' => '01000000001']);
    }

    private function buy(string $chassis, array $over = []): array
    {
        return $this->postJson('/api/purchases', $over + [
            'vehicle_type' => 'motorcycle', 'chassis_no' => $chassis, 'engine_no' => "EN-$chassis", 'brand' => 'Honda', 'model' => 'CB',
            'supplier_id' => $this->supplier->id, 'purchase_date' => today()->toDateString(), 'price' => 300000,
        ])->assertCreated()->json('data');
    }

    private function financedSale(string $chassis, Investor $investor): array
    {
        return $this->postJson('/api/sales', [
            'vehicle_id' => $this->buy($chassis)['vehicle_id'], 'customer_id' => Customer::create(['name' => "Buyer $chassis", 'phone' => "02$chassis"])->id,
            'sale_date' => today()->toDateString(), 'sale_price' => 500000, 'payment_type' => 'installment', 'down_payment' => 200000,
            'investor_id' => $investor->id, 'investor_share_percent' => 50, 'installment_count' => 6,
            'first_due_date' => today()->addMonth()->toDateString(), 'interest_rate' => 10,
        ])->assertCreated()->json('data');
    }

    public function test_settlements_the_system_made_cannot_be_edited_or_deleted_by_hand(): void
    {
        $investor = Investor::create(['name' => 'Nasir']);
        $sale = $this->financedSale('111', $investor);
        $this->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 50000])->assertCreated();

        $auto = InvestorSettlement::where('investor_id', $investor->id)->get();
        $this->assertCount(3, $auto); // the investment, and the principal and profit shares of the collection

        foreach ($auto as $s) {
            $this->putJson("/api/investor-settlements/{$s->id}", ['investor_id' => $investor->id, 'type' => $s->type, 'amount' => 1, 'date' => today()->toDateString()])->assertStatus(409);
            $this->deleteJson("/api/investor-settlements/{$s->id}")->assertStatus(409);
        }
        $this->assertSame(3, InvestorSettlement::where('investor_id', $investor->id)->count());

        // a settlement entered by hand still can
        $manual = $this->postJson('/api/investor-settlements', ['investor_id' => $investor->id, 'type' => 'withdrawal', 'amount' => 1000, 'date' => today()->toDateString()])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/investor-settlements/$manual", ['investor_id' => $investor->id, 'type' => 'withdrawal', 'amount' => 2000, 'date' => today()->toDateString()])->assertOk();
        $this->deleteJson("/api/investor-settlements/$manual")->assertOk();

        // nor can one be made to look like the system's
        $this->postJson('/api/investor-settlements', ['investor_id' => $investor->id, 'type' => 'withdrawal', 'amount' => 1000, 'date' => today()->toDateString(), 'note' => 'Auto: fake'])
            ->assertStatus(422)->assertJsonValidationErrors('note');
    }

    public function test_a_settlement_goes_only_on_a_plan_the_investor_finances(): void
    {
        $nasir = Investor::create(['name' => 'Nasir']);
        $rahim = Investor::create(['name' => 'Rahim']);
        $sale = $this->financedSale('222', $nasir);
        $data = ['type' => 'withdrawal', 'amount' => 1000, 'date' => today()->toDateString(), 'plan_id' => $sale['plan']['id']];

        $this->postJson('/api/investor-settlements', ['investor_id' => $rahim->id] + $data)->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->postJson('/api/investor-settlements', ['investor_id' => $nasir->id] + $data)->assertCreated();
    }

    public function test_a_purchase_paid_total_is_summed_again_from_its_payments(): void
    {
        $p = $this->buy('333', ['paid_amount' => 100000, 'payment_method' => 'cash']);
        $this->assertDatabaseHas('purchases', ['id' => $p['id'], 'initial_paid' => 100000]);

        // drifted (a manual fix, a failed edit): the next payment puts it right
        DB::table('purchases')->where('id', $p['id'])->update(['paid_amount' => 150000]);
        $this->postJson("/api/purchases/{$p['id']}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->assertEquals(101000, Purchase::find($p['id'])->paid_amount);

        // and so does an edit or a cancel of a payment
        $pay = PurchasePayment::where('purchase_id', $p['id'])->firstOrFail();
        DB::table('purchases')->where('id', $p['id'])->update(['paid_amount' => 1]);
        $this->putJson("/api/transactions/supplier_payment/{$pay->id}", ['amount' => 5000, 'paid_at' => today()->toDateString(), 'method' => 'cash'])->assertOk();
        $this->assertEquals(105000, Purchase::find($p['id'])->paid_amount);

        $this->postJson("/api/transactions/supplier_payment/{$pay->id}/cancel", ['reason' => 'Entered twice'])->assertOk();
        $purchase = Purchase::find($p['id']);
        $this->assertEquals(100000, $purchase->paid_amount);
        $this->assertSame('partial', $purchase->payment_status);
    }

    public function test_a_new_password_signs_the_user_out_everywhere(): void
    {
        $id = $this->postJson('/api/users', ['name' => 'Ad', 'email' => 'ad@t.test', 'password' => 'password1', 'role' => 'admin'])->assertCreated()->json('data.id');
        $user = User::findOrFail($id);
        $user->createToken('phone');
        $user->createToken('laptop');

        // an edit without a password keeps them signed in
        $this->putJson("/api/users/$id", ['name' => 'Ad', 'email' => 'ad@t.test', 'role' => 'admin'])->assertOk();
        $this->assertSame(2, $user->tokens()->count());

        $this->putJson("/api/users/$id", ['name' => 'Ad', 'email' => 'ad@t.test', 'role' => 'admin', 'password' => 'newpassword1'])->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_booked_sale_keeps_its_buyer(): void
    {
        $vehicleId = $this->buy('444')['vehicle_id'];
        $buyer = Customer::create(['name' => 'Buyer', 'phone' => '02000000444']);
        $other = Customer::create(['name' => 'Other', 'phone' => '02000000445']);
        $booking = $this->postJson('/api/bookings', ['vehicle_id' => $vehicleId, 'customer_id' => $buyer->id, 'agreed_price' => 500000, 'advance_amount' => 100000])
            ->assertCreated()->json('data');
        $data = ['vehicle_id' => $vehicleId, 'sale_date' => today()->toDateString(), 'sale_price' => 500000, 'payment_type' => 'due', 'down_payment' => 100000,
            'promised_date' => today()->addMonth()->toDateString()];
        $sale = $this->postJson('/api/sales', ['booking_id' => $booking['id'], 'customer_id' => $buyer->id] + $data)->assertCreated()->json('data');

        $this->putJson("/api/sales/{$sale['id']}", ['customer_id' => $other->id] + $data)->assertStatus(422)->assertJsonValidationErrors('customer_id');
        $this->assertDatabaseHas('sales', ['id' => $sale['id'], 'customer_id' => $buyer->id]);
        $this->putJson("/api/sales/{$sale['id']}", ['customer_id' => $buyer->id, 'memo_no' => 'M-1'] + $data)->assertOk();
    }
}
