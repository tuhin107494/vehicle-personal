<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Customer;
use App\Models\DuePromise;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ShopService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\VehicleCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollectionCallTest extends TestCase
{
    use RefreshDatabase;

    private DuePromise $promise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(VehicleCategorySeeder::class);
        $shop = app(ShopService::class)->create(['name' => 'Shop A'], ['name' => 'Owner', 'email' => 'owner@a.test', 'password' => 'password']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'shop_id' => $shop->id]));

        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01001234567']);
        $vehicleId = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-1', 'engine_no' => 'EN-1', 'brand' => 'Honda', 'model' => 'CBR',
            'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 100000,
        ])->assertCreated()->json('data.vehicle_id');

        $this->postJson('/api/sales', [
            'vehicle_id' => Vehicle::findOrFail($vehicleId)->id, 'customer_id' => Customer::create(['name' => 'Buyer', 'phone' => '02001234567'])->id,
            'sale_date' => today()->subDay()->toDateString(), 'sale_price' => 150000, 'payment_type' => 'due', 'down_payment' => 50000,
            'promised_date' => today()->toDateString(),
        ])->assertCreated();

        $this->promise = DuePromise::firstOrFail();
    }

    /** the day the row is marked called until (null: a call is due) */
    private function calledUntil(): ?string
    {
        $rows = $this->getJson('/api/collections/upcoming?bucket=d3')->assertOk()->json('data');
        $this->assertCount(1, $rows);

        return $rows[0]['called_until'];
    }

    public function test_a_called_row_stays_marked_until_the_recall_days_pass(): void
    {
        $this->assertNull($this->calledUntil());

        $this->postJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id])->assertOk();
        $this->assertSame(today()->addDays(7)->toDateString(), $this->calledUntil());

        // the default is 7 days: still marked on day 6, due for a call again on day 7
        CallLog::query()->update(['called_at' => now()->subDays(6)]);
        $this->assertNotNull($this->calledUntil());
        CallLog::query()->update(['called_at' => now()->subDays(7)]);
        $this->assertNull($this->calledUntil());
    }

    public function test_the_keep_selection_setting_is_off_until_a_shop_turns_it_on(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.collections.keep_selection', false);

        $this->putJson('/api/settings', ['collections' => ['keep_selection' => true]])->assertOk()->assertJsonPath('data.collections.keep_selection', true);
        $this->putJson('/api/settings', ['collections' => ['keep_selection' => false]])->assertOk()->assertJsonPath('data.collections.keep_selection', false);
    }

    public function test_the_auto_date_setting_is_off_until_a_shop_turns_it_on(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.forms.auto_date', false);

        $this->putJson('/api/settings', ['forms' => ['auto_date' => true]])->assertOk()->assertJsonPath('data.forms.auto_date', true);
    }

    public function test_the_memo_number_field_is_on_until_a_shop_turns_it_off(): void
    {
        $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.forms.memo_no', true);

        $this->putJson('/api/settings', ['forms' => ['memo_no' => false]])->assertOk()->assertJsonPath('data.forms.memo_no', false);
    }

    public function test_the_recall_days_setting_changes_when_a_row_comes_back(): void
    {
        $this->putJson('/api/settings', ['collections' => ['recall_days' => 15]])->assertOk()->assertJsonPath('data.collections.recall_days', 15);
        $this->postJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id])->assertOk();

        CallLog::query()->update(['called_at' => now()->subDays(10)]);
        $this->assertNotNull($this->calledUntil());
        CallLog::query()->update(['called_at' => now()->subDays(15)]);
        $this->assertNull($this->calledUntil());
    }

    public function test_the_call_history_lists_every_call_and_undo_keeps_the_earlier_ones(): void
    {
        $body = ['type' => 'promise', 'id' => $this->promise->id];
        $this->postJson('/api/collections/call', $body + ['note' => 'First call'])->assertOk();
        CallLog::query()->update(['called_at' => now()->subDays(9)]);
        $this->postJson('/api/collections/call', $body + ['note' => 'Second call', 'meet_date' => today()->addDays(3)->toDateString()])->assertOk();

        $history = $this->getJson('/api/collections/call-history?type=promise&id='.$this->promise->id)->assertOk()->json('data');
        $this->assertSame(['Second call', 'First call'], array_column($history, 'note'));
        $this->assertSame('Due', $history[0]['for']);
        $this->assertNotNull($history[0]['by']);

        // undo removes only the latest mark
        $this->deleteJson('/api/collections/call', $body)->assertOk();
        $this->assertSame(['First call'], array_column($this->getJson('/api/collections/call-history?type=promise&id='.$this->promise->id)->json('data'), 'note'));
    }

    public function test_a_call_mark_can_be_undone(): void
    {
        $this->postJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id])->assertOk();
        $this->deleteJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id])->assertOk();

        $this->assertNull($this->calledUntil());
    }

    public function test_the_day_the_customer_will_meet_is_kept_and_sets_the_next_call(): void
    {
        $meet = today()->addDays(12)->toDateString();
        $this->postJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id, 'meet_date' => $meet, 'note' => 'Will come to the shop'])->assertOk();

        $row = $this->getJson('/api/collections/upcoming?bucket=d3')->assertOk()->json('data.0');
        $this->assertSame($meet, $row['called_until']);
        $this->assertSame($meet, $row['called_meet_date']);
        $this->assertSame('Will come to the shop', $row['called_note']);

        // the meeting day is not allowed in the past
        $this->postJson('/api/collections/call', ['type' => 'promise', 'id' => $this->promise->id, 'meet_date' => today()->subDay()->toDateString()])->assertUnprocessable();
    }
}
