<?php

namespace Tests\Feature;

use App\Models\Booking;
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

class BookingTest extends TestCase
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

        $seller = Supplier::create(['name' => 'Seller', 'phone' => '01001234567']);
        $id = $this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-1', 'engine_no' => 'EN-1', 'brand' => 'Honda', 'model' => 'CBR',
            'supplier_id' => $seller->id, 'purchase_date' => today()->toDateString(), 'price' => 400000,
        ])->assertCreated()->json('data.vehicle_id');

        $this->vehicle = Vehicle::findOrFail($id);
        $this->customer = Customer::create(['name' => 'Buyer', 'phone' => '02001234567']);
    }

    private function book(array $over = []): array
    {
        return $this->postJson('/api/bookings', $over + [
            'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'agreed_price' => 500000, 'advance_amount' => 300000,
        ])->assertCreated()->json('data');
    }

    public function test_a_booking_holds_the_vehicle_so_nobody_else_can_buy_it(): void
    {
        $booking = $this->book();

        $this->assertSame('booked', $this->vehicle->fresh()->status);
        $this->assertSame('active', $booking['status']);
        $this->assertEquals(200000, $booking['balance']);

        $this->postJson('/api/sales', [
            'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'sale_date' => today()->toDateString(),
            'sale_price' => 500000, 'payment_type' => 'cash',
        ])->assertStatus(422);

        // and it cannot be booked twice
        $this->postJson('/api/bookings', [
            'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'agreed_price' => 500000, 'advance_amount' => 100000,
        ])->assertStatus(409);
    }

    public function test_an_active_booking_can_be_edited_and_the_hold_moves_with_the_vehicle(): void
    {
        $booking = $this->book();
        $other = Vehicle::create(['vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-2', 'engine_no' => 'EN-2', 'brand' => 'Yamaha', 'model' => 'R15', 'status' => 'in_stock']);

        $this->putJson("/api/bookings/{$booking['id']}", [
            'vehicle_id' => $other->id, 'customer_id' => $this->customer->id, 'agreed_price' => 600000, 'advance_amount' => 350000, 'note' => 'changed',
        ])->assertOk()->assertJsonPath('data.vehicle_id', $other->id)->assertJsonPath('data.note', 'changed');

        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
        $this->assertSame('booked', $other->fresh()->status);
        $this->assertEquals(350000, Booking::find($booking['id'])->advance_amount);

        $this->putJson("/api/bookings/{$booking['id']}", ['customer_id' => $this->customer->id, 'agreed_price' => 600000, 'advance_amount' => 700000])->assertStatus(422);
    }

    public function test_buying_from_customers_can_be_turned_off_in_settings(): void
    {
        $this->assertTrue($this->getJson('/api/settings')->json('data.purchase.from_customer'));
        $this->putJson('/api/settings', ['purchase' => ['from_customer' => false]])->assertOk();
        $this->assertFalse($this->getJson('/api/settings')->json('data.purchase.from_customer'));

        $this->postJson('/api/purchases', [
            'source' => 'customer', 'customer_id' => $this->customer->id, 'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-9', 'engine_no' => 'EN-9',
            'purchase_date' => today()->toDateString(), 'price' => 100000,
        ])->assertStatus(422)->assertJsonValidationErrors('source');

        $this->putJson('/api/settings', ['purchase' => ['from_customer' => true]])->assertOk();
        $this->assertTrue($this->getJson('/api/settings')->json('data.purchase.from_customer'));
    }

    public function test_a_closed_booking_cannot_be_edited(): void
    {
        $booking = $this->book();
        $this->postJson("/api/bookings/{$booking['id']}/cancel", [])->assertOk();

        $this->putJson("/api/bookings/{$booking['id']}", ['customer_id' => $this->customer->id, 'advance_amount' => 1000])->assertStatus(409);
    }

    public function test_the_sale_date_cannot_be_before_the_booking_date(): void
    {
        $booking = $this->book();
        Booking::whereKey($booking['id'])->update(['booked_on' => today()->subDays(3)]);

        $this->postJson('/api/sales', [
            'booking_id' => $booking['id'], 'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'sale_date' => today()->subDays(5)->toDateString(),
            'sale_price' => 500000, 'payment_type' => 'cash', 'down_payment' => 500000, 'method' => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors('sale_date');
    }

    public function test_the_stock_list_shows_a_booked_vehicle_marked_booked(): void
    {
        $this->book();

        $this->getJson('/api/vehicles?status=stock')->assertOk()->assertJsonPath('data.0.status', 'booked');
        $this->assertCount(0, $this->getJson('/api/vehicles?status=in_stock')->json('data'));
    }

    public function test_a_sale_draft_holds_its_vehicle_until_it_is_deleted_or_becomes_the_sale(): void
    {
        $values = ['vehicle_id' => (string) $this->vehicle->id, 'party_id' => (string) $this->customer->id];
        $id = $this->postJson('/api/drafts', ['type' => 'sale', 'label' => 'D', 'data' => ['values' => $values]])->assertCreated()->json('data.id');
        $this->assertSame('booked', $this->vehicle->fresh()->status);

        // nobody can book it or put it in another draft meanwhile
        $this->postJson('/api/bookings', ['vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'advance_amount' => 1000])->assertStatus(409);
        $this->postJson('/api/drafts', ['type' => 'sale', 'data' => ['values' => $values]])->assertStatus(409);

        // the booking list shows the draft with its vehicle
        $this->getJson('/api/bookings')->assertOk()->assertJsonPath('meta.drafts.0.vehicle.chassis_no', 'CH-1')->assertJsonPath('meta.status_counts.active', 1);

        // choosing no vehicle releases it
        $this->putJson("/api/drafts/$id", ['data' => ['values' => ['vehicle_id' => '']]])->assertOk();
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);

        // the draft holds it again, and the sale made from the draft sells it
        $this->putJson("/api/drafts/$id", ['data' => ['values' => $values]])->assertOk();
        $this->postJson('/api/sales', [
            'draft_id' => $id, 'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'sale_date' => today()->toDateString(),
            'sale_price' => 500000, 'payment_type' => 'cash', 'down_payment' => 500000, 'method' => 'cash',
        ])->assertCreated();
        $this->assertSame('sold', $this->vehicle->fresh()->status);
        $this->assertDatabaseMissing('drafts', ['id' => $id]);

        // deleting a draft frees its vehicle
        $other = Vehicle::create(['vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-3', 'engine_no' => 'EN-3', 'brand' => 'Yamaha', 'model' => 'R15', 'status' => 'in_stock']);
        $d2 = $this->postJson('/api/drafts', ['type' => 'sale', 'data' => ['values' => ['vehicle_id' => (string) $other->id]]])->assertCreated()->json('data.id');
        $this->assertSame('booked', $other->fresh()->status);
        $this->deleteJson("/api/drafts/$d2")->assertOk();
        $this->assertSame('in_stock', $other->fresh()->status);
    }

    public function test_a_sale_draft_deletes_itself_after_its_date_and_frees_the_vehicle(): void
    {
        $id = $this->postJson('/api/drafts', ['type' => 'sale', 'data' => ['values' => ['vehicle_id' => (string) $this->vehicle->id]]])->assertCreated()
            ->assertJsonPath('data.expires_on', today()->addDays(7)->toDateString())->json('data.id');

        $this->getJson('/api/bookings')->assertJsonCount(1, 'meta.drafts'); // still within its days
        \App\Models\Draft::whereKey($id)->update(['expires_on' => today()->subDay()]);

        $this->getJson('/api/bookings')->assertOk()->assertJsonMissingPath('meta.drafts.0');
        $this->assertDatabaseMissing('drafts', ['id' => $id]);
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);

        // a purchase draft never expires
        $this->postJson('/api/drafts', ['type' => 'purchase', 'data' => ['values' => []]])->assertCreated()->assertJsonPath('data.expires_on', null);
        $this->artisan('drafts:expire')->assertSuccessful();
    }

    public function test_the_advance_must_be_less_than_the_price(): void
    {
        $this->postJson('/api/bookings', ['vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id, 'agreed_price' => 500000, 'advance_amount' => 500000])
            ->assertStatus(422)->assertJsonValidationErrors('advance_amount');
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
    }

    private function sell(array $booking, array $over = []): TestResponse
    {
        return $this->postJson('/api/sales', $over + [
            'booking_id' => $booking['id'], 'vehicle_id' => $this->vehicle->id, 'customer_id' => $this->customer->id,
            'sale_date' => today()->toDateString(), 'sale_price' => 500000, 'payment_type' => 'cash',
        ]);
    }

    public function test_a_booking_becomes_a_cash_sale_and_the_advance_is_counted_once(): void
    {
        $booking = $this->book();

        $this->sell($booking, ['method' => 'bank'])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertSame('sold', $this->vehicle->fresh()->status);
        $this->assertEquals(0, $sale->due_amount);
        $this->assertEquals(500000, $sale->payments()->sum('amount'));
        $this->assertCount(2, $sale->payments); // the advance, and the balance paid now
        $this->assertSame('completed', Booking::firstOrFail()->status);
        $this->assertSame($sale->id, Booking::firstOrFail()->sale_id);

        // cash: 300,000 on the booking and 200,000 at the sale, not 300,000 twice
        $day = $this->getJson('/api/reports/daily?date='.today()->toDateString())->assertOk()->json('data');
        $this->assertEquals(300000, $day['booking_advances']['total']);
        $this->assertEquals(200000, $day['sales']['received']);
        $this->assertEquals(500000, $day['cash']['in']);

        // the sale page shows the advance and the day it was received
        $this->getJson("/api/sales/{$sale->id}")->assertOk()
            ->assertJsonPath('data.booking.advance_amount', 300000)->assertJsonPath('data.booking.booked_on', today()->toDateString());

        // the same booking cannot be sold twice
        $this->sell($booking)->assertStatus(422)->assertJsonValidationErrors('booking_id');
    }

    public function test_a_booking_can_become_a_due_sale_with_more_paid_on_top_of_the_advance(): void
    {
        $booking = $this->book();

        $this->sell($booking, ['payment_type' => 'due', 'down_payment' => 350000, 'promised_date' => today()->addDays(10)->toDateString()])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertEquals(150000, $sale->due_amount);
        $this->assertEquals(350000, $sale->payments()->sum('amount'));
        $this->assertSame('sold', $this->vehicle->fresh()->status);
        $this->assertSame('completed', Booking::firstOrFail()->status);
    }

    public function test_a_booking_can_become_an_installment_sale(): void
    {
        $booking = $this->book();

        $this->sell($booking, [
            'payment_type' => 'installment', 'down_payment' => 300000, 'installment_count' => 4, 'first_due_date' => today()->addMonth()->toDateString(),
            'frequency' => 'monthly', 'interest_rate' => 0,
        ])->assertCreated();

        $sale = Sale::firstOrFail();
        $this->assertSame('installment', $sale->payment_type);
        $this->assertEquals(200000, $sale->due_amount);
        $this->assertCount(4, $sale->plan->installments);
    }

    public function test_the_buyer_can_take_another_vehicle_than_the_booked_one(): void
    {
        $booking = $this->book();
        $other = Vehicle::findOrFail($this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-2', 'engine_no' => 'EN-2', 'brand' => 'Yamaha', 'model' => 'R15',
            'supplier_id' => Supplier::firstOrFail()->id, 'purchase_date' => today()->toDateString(), 'price' => 300000,
        ])->assertCreated()->json('data.vehicle_id'));

        $this->sell($booking, ['vehicle_id' => $other->id, 'sale_price' => 450000, 'payment_type' => 'due', 'down_payment' => 300000])->assertCreated();

        $this->assertSame('in_stock', $this->vehicle->fresh()->status); // the booked one is released
        $this->assertSame('sold', $other->fresh()->status);
        $this->assertSame($other->id, Booking::firstOrFail()->vehicle_id);
        $this->assertSame('completed', Booking::firstOrFail()->status);
        $this->assertEquals(150000, Sale::firstOrFail()->due_amount);
    }

    public function test_another_vehicle_must_be_in_stock(): void
    {
        $booking = $this->book();
        $sold = Vehicle::findOrFail($this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-3', 'engine_no' => 'EN-3', 'brand' => 'Bajaj', 'model' => 'Pulsar',
            'supplier_id' => Supplier::firstOrFail()->id, 'purchase_date' => today()->toDateString(), 'price' => 200000,
        ])->assertCreated()->json('data.vehicle_id'));
        $sold->update(['status' => 'sold']);

        $this->sell($booking, ['vehicle_id' => $sold->id])->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
        $this->assertSame('booked', $this->vehicle->fresh()->status);
    }

    public function test_a_booking_can_be_made_before_a_vehicle_is_chosen_and_the_vehicle_is_picked_at_the_sale(): void
    {
        $booking = $this->postJson('/api/bookings', ['customer_id' => $this->customer->id, 'agreed_price' => 450000, 'advance_amount' => 20000])
            ->assertCreated()->assertJsonPath('data.vehicle_id', null)->assertJsonPath('data.vehicle', null)->json('data');

        // nothing is held: the vehicle is still on sale to anyone
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
        $this->getJson('/api/bookings?status=active')->assertOk()->assertJsonCount(1, 'data');

        // the day's cash counts the advance, with no vehicle on the row
        $day = $this->getJson('/api/reports/daily?date='.today()->toDateString())->assertOk()->json('data');
        $this->assertEquals(20000, $day['booking_advances']['total']);

        // at the sale the buyer picks the vehicle, which must be in stock
        $this->sell($booking, ['sale_price' => 450000, 'payment_type' => 'due', 'down_payment' => 120000])->assertCreated();

        $this->assertSame('sold', $this->vehicle->fresh()->status);
        $this->assertSame($this->vehicle->id, Booking::firstOrFail()->vehicle_id);
        $this->assertSame('completed', Booking::firstOrFail()->status);
        $this->assertEquals(330000, Sale::firstOrFail()->due_amount);
    }

    public function test_a_booking_can_be_made_with_no_price_yet(): void
    {
        $booking = $this->postJson('/api/bookings', ['customer_id' => $this->customer->id, 'advance_amount' => 20000])
            ->assertCreated()->assertJsonPath('data.agreed_price', null)->assertJsonPath('data.balance', null)->json('data');

        $this->getJson('/api/bookings?status=active')->assertOk()->assertJsonPath('data.0.balance', null);

        // the price is set at the sale
        $this->sell($booking, ['sale_price' => 450000, 'payment_type' => 'due', 'down_payment' => 120000])->assertCreated();
        $this->assertEquals(330000, Sale::firstOrFail()->due_amount);
        $this->assertSame('completed', Booking::firstOrFail()->status);
    }

    public function test_an_advance_still_must_be_less_than_a_price_that_is_given(): void
    {
        $this->postJson('/api/bookings', ['customer_id' => $this->customer->id, 'agreed_price' => 20000, 'advance_amount' => 20000])
            ->assertStatus(422)->assertJsonValidationErrors('advance_amount');
    }

    public function test_a_booking_can_be_made_with_no_advance_and_sold_later(): void
    {
        $booking = $this->book(['advance_amount' => 0, 'agreed_price' => 500000]);
        $this->assertSame('booked', $this->vehicle->fresh()->status);
        $this->assertEquals(500000, $booking['balance']);

        $this->postJson('/api/bookings', ['customer_id' => $this->customer->id, 'vehicle_id' => null])->assertCreated(); // not even a number is needed

        $sale = $this->sell($booking, ['sale_price' => 500000, 'payment_type' => 'cash', 'down_payment' => 500000])->assertCreated()->json('data');
        $this->assertNull($sale['advance']); // nothing was received before the sale
        $this->assertSame(500000.0, (float) Sale::findOrFail($sale['id'])->total_paid);
        $this->assertSame('completed', Booking::findOrFail($booking['id'])->status);
    }

    public function test_a_booking_with_no_deadline_is_never_expired(): void
    {
        $booking = $this->book();
        $this->assertNull($booking['expires_on']);
        $this->assertNull($booking['days_left']);

        $this->travel(2)->years();
        $this->getJson('/api/bookings?status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('booked', $this->vehicle->fresh()->status);
    }

    public function test_a_given_deadline_is_kept_and_ones_without_come_last(): void
    {
        $open = $this->book();
        $other = Vehicle::findOrFail($this->postJson('/api/purchases', [
            'vehicle_type' => 'motorcycle', 'chassis_no' => 'CH-5', 'engine_no' => 'EN-5', 'brand' => 'Yamaha', 'model' => 'R15',
            'supplier_id' => Supplier::firstOrFail()->id, 'purchase_date' => today()->toDateString(), 'price' => 300000,
        ])->assertCreated()->json('data.vehicle_id'));
        $dated = $this->book(['vehicle_id' => $other->id, 'expires_on' => today()->addDays(5)->toDateString()]);

        $this->assertSame(5, $dated['days_left']);
        $this->getJson('/api/bookings?status=active')->assertOk()->assertJsonPath('data.0.id', $dated['id'])->assertJsonPath('data.1.id', $open['id']);
    }

    public function test_a_booking_without_a_vehicle_can_be_cancelled(): void
    {
        $booking = $this->postJson('/api/bookings', ['customer_id' => $this->customer->id, 'agreed_price' => 450000, 'advance_amount' => 20000])->assertCreated()->json('data');

        $this->postJson("/api/bookings/{$booking['id']}/cancel", ['refund_amount' => 20000])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
    }

    public function test_the_sale_must_keep_the_booking_advance_and_buyer(): void
    {
        $booking = $this->book();
        $other = Customer::create(['name' => 'Someone else', 'phone' => '03001234567']);

        $this->sell($booking, ['payment_type' => 'due', 'down_payment' => 100000])->assertStatus(422)->assertJsonValidationErrors('down_payment');
        $this->sell($booking, ['customer_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('booking_id');
        $this->assertSame('booked', $this->vehicle->fresh()->status);
        $this->assertSame('active', Booking::firstOrFail()->status);
    }

    public function test_cancelling_releases_the_vehicle_and_the_refund_can_be_set(): void
    {
        $booking = $this->book();

        $this->postJson("/api/bookings/{$booking['id']}/cancel", ['refund_amount' => 250000, 'note' => 'changed mind'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.refund_amount', '250000.00')->assertJsonPath('data.kept_amount', '50000.00');
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);

        $cash = $this->getJson('/api/reports/daily?date='.today()->toDateString())->json('data');
        $this->assertEquals(250000, $cash['booking_refunds']['total']);
        $this->assertEquals(300000, $cash['booking_advances']['total']);
    }

    public function test_a_refund_cannot_be_more_than_the_advance(): void
    {
        $booking = $this->book();

        $this->postJson("/api/bookings/{$booking['id']}/cancel", ['refund_amount' => 300001])->assertStatus(409);
        $this->assertSame('booked', $this->vehicle->fresh()->status);
    }

    public function test_the_shop_policy_fills_in_the_refund_when_none_is_given(): void
    {
        $this->putJson('/api/settings', ['booking' => ['on_cancel' => 'keep']])->assertOk();
        $booking = $this->book();

        $this->postJson("/api/bookings/{$booking['id']}/cancel")->assertOk()->assertJsonPath('data.refund_amount', '0.00')->assertJsonPath('data.kept_amount', '300000.00');
    }

    public function test_a_booking_past_its_deadline_expires_and_frees_the_vehicle(): void
    {
        $booking = $this->book();
        Booking::whereKey($booking['id'])->update(['expires_on' => today()->subDay()->toDateString()]);

        $this->getJson('/api/bookings')->assertOk()->assertJsonPath('data.0.status', 'expired')->assertJsonPath('data.0.refund_amount', '300000.00');
        $this->assertSame('in_stock', $this->vehicle->fresh()->status);
        $this->sell($booking)->assertStatus(422)->assertJsonValidationErrors('booking_id');
    }

    public function test_sale_drafts_are_listed_with_the_bookings(): void
    {
        $this->postJson('/api/drafts', ['type' => 'sale', 'label' => 'CH-9 · 100,000 · due', 'data' => ['values' => ['sale_price' => '100000']]])->assertCreated();
        $this->postJson('/api/drafts', ['type' => 'purchase', 'label' => 'a purchase draft', 'data' => ['values' => []]])->assertCreated();
        $this->book();

        // the drafts are listed with the active bookings (and counted as active); only sale drafts belong here
        $this->getJson('/api/bookings')->assertOk()->assertJsonPath('meta.status_counts.active', 2)
            ->assertJsonCount(1, 'meta.drafts')->assertJsonPath('meta.drafts.0.label', 'CH-9 · 100,000 · due')->assertJsonPath('meta.drafts.0.type', 'sale')
            ->assertJsonCount(1, 'data');

        // the cancelled and expired tabs carry no drafts
        $this->getJson('/api/bookings?status=cancelled')->assertOk()->assertJsonMissingPath('meta.drafts');
        $this->getJson('/api/bookings?status=draft')->assertStatus(422);
    }

    public function test_the_list_filters_by_status_and_counts_each(): void
    {
        $this->book();

        $this->getJson('/api/bookings?status=active')->assertOk()->assertJsonPath('meta.status_counts.active', 1)->assertJsonCount(1, 'data');
        $this->getJson('/api/bookings?status=cancelled')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/bookings?status=nope')->assertStatus(422);
    }
}
