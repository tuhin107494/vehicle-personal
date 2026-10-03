<?php

namespace Database\Seeders;

use App\Models\Investor;
use App\Models\Party;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * One CNG that has changed hands several times: bought from a supplier, sold, bought back from the customer
 * (setting its due off against the price), sold again, bought back again and sold a third time.
 * Safe to run more than once. Run with: php artisan db:seed --class=DemoVehicleHistorySeeder
 */
class DemoVehicleHistorySeeder extends Seeder
{
    public function run(PurchaseService $purchases, SaleService $sales): void
    {
        if (Vehicle::where('chassis_no', 'DH-3001')->exists()) {
            return;
        }

        $owner = User::where('role', 'shop_owner')->firstOrFail();
        Auth::setUser($owner); // so rows are stamped with the shop

        $day = fn (int $offset) => today()->addDays($offset)->toDateString();
        $supplier = Party::firstOrCreate(['phone' => '01711000001'], ['name' => 'Karim Motors', 'type' => 'supplier']);
        $investor = Investor::firstOrCreate(['name' => 'Nasir Capital'], ['phone' => '01511000005', 'opening_balance' => 0]);
        $customer = fn (string $phone, string $name, string $nid, string $address) => Party::firstOrCreate(
            ['phone' => $phone], ['name' => $name, 'nid' => $nid, 'address' => $address, 'type' => 'customer'],
        );

        $first = $customer('01712340001', 'Abdul Karim', '1985123400011', 'Mirpur 10, Dhaka');
        $second = $customer('01712340002', 'Rina Begum', '1992123400022', 'Uttara Sector 7, Dhaka');
        $third = $customer('01712340005', 'Mizanur Rahman', '1988123400055', 'Mohammadpur, Dhaka');

        // 1. bought from the supplier
        $vehicle = $purchases->create([
            'vehicle_type' => 'cng', 'chassis_no' => 'DH-3001', 'engine_no' => 'DHE-3001', 'brand' => 'Bajaj', 'model' => 'RE Compact', 'year' => 2021,
            'color' => 'Green', 'cc' => 200, 'fuel_type' => 'CNG', 'registration_no' => 'DHAKA METRO-THA-11-3001',
            'registration_date' => today()->subYears(3)->toDateString(), 'tax_token_expiry' => today()->addMonths(8)->toDateString(),
            'fitness_expiry' => today()->addMonths(5)->toDateString(), 'insurance_expiry' => today()->addMonths(9)->toDateString(),
            'papers_received' => true, 'mileage' => 24000,
            'source' => 'supplier', 'party_id' => $supplier->id, 'memo_no' => 'KM-3001', 'purchase_date' => $day(-240),
            'price' => 250000, 'paid_amount' => 250000, 'payment_method' => 'bank',
        ], $owner)->vehicle;

        // 2. sold to the first customer with a due
        $saleOne = $sales->create([
            'vehicle_id' => $vehicle->id, 'party_id' => $first->id, 'sale_date' => $day(-210), 'sale_price' => 300000,
            'payment_type' => 'due', 'down_payment' => 100000, 'promised_date' => $day(-150), 'promise_note' => 'Will pay after harvest',
        ], $owner);

        // 3. bought back from him: 200,000 of his due is set off, the other 20,000 is paid in cash
        $purchases->create([
            'vehicle_id' => $vehicle->id, 'source' => 'customer', 'party_id' => $first->id, 'purchase_date' => $day(-120),
            'price' => 220000, 'paid_amount' => 20000, 'payment_method' => 'cash',
            'due_adjustment' => 200000, 'adjusted_sale_id' => $saleOne->id, 'notes' => 'Could not keep up with the payments',
        ], $owner);

        // 4. sold to the second customer in installments
        $saleTwo = $sales->create([
            'vehicle_id' => $vehicle->id, 'party_id' => $second->id, 'sale_date' => $day(-90), 'sale_price' => 310000,
            'payment_type' => 'installment', 'down_payment' => 110000, 'investor_id' => $investor->id,
            'installment_count' => 5, 'first_due_date' => $day(-60), 'frequency' => 'monthly', 'interest_amount' => 20000, 'investor_share_percent' => 50,
        ], $owner);

        // 5. bought back from her, setting 180,000 off against the installments still owed
        $purchases->create([
            'vehicle_id' => $vehicle->id, 'source' => 'customer', 'party_id' => $second->id, 'purchase_date' => $day(-30),
            'price' => 200000, 'paid_amount' => 20000, 'payment_method' => 'cash',
            'due_adjustment' => 180000, 'adjusted_sale_id' => $saleTwo->id,
        ], $owner);

        // 6. sold for cash to the third customer, who still holds it
        $sales->create([
            'vehicle_id' => $vehicle->id, 'party_id' => $third->id, 'sale_date' => $day(-10), 'sale_price' => 290000,
            'payment_type' => 'cash', 'down_payment' => 290000,
        ], $owner);
    }
}
