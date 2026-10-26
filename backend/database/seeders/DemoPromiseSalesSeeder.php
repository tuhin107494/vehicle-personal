<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Three demo due sales whose buyer promised to pay today, so "Promises due today" has rows.
 * Safe to run more than once: a vehicle that already exists is skipped.
 * Run with: php artisan db:seed --class=DemoPromiseSalesSeeder
 */
class DemoPromiseSalesSeeder extends Seeder
{
    public function run(PurchaseService $purchases, SaleService $sales): void
    {
        $owner = User::where('role', 'shop_owner')->firstOrFail();
        Auth::setUser($owner); // so rows are stamped with the shop

        $supplier = Supplier::firstOrCreate(['phone' => '01711000001'], ['name' => 'Karim Motors']);
        $day = fn (int $offset) => today()->addDays($offset)->toDateString();

        // [customer, nid, mobile, alt mobile, address], [type, chassis, engine, brand, model, year, colour, cc, fuel, reg no, cost], sale
        $rows = [
            [['Jahid Hasan', '1987123400077', '01712340007', null, 'Mirpur 2, Dhaka'],
                ['motorcycle', 'DC-2007', 'DE-2007', 'Suzuki', 'Gixxer', 2022, 'Red', 155, 'Octane', 'DHAKA METRO-LA-19-2007', 170000],
                ['sale_date' => $day(-10), 'sale_price' => 205000, 'payment_type' => 'due', 'down_payment' => 70000, 'promised_date' => $day(0), 'promise_note' => 'Will pay today']],
            [['Sumon Mia', '1983123400088', '01712340008', '01812340088', 'Gazipur Sadar'],
                ['cng', 'DC-2008', 'DE-2008', 'Bajaj', 'RE Compact', 2021, 'Green', 200, 'CNG', 'DHAKA METRO-THA-11-2008', 260000],
                ['sale_date' => $day(-15), 'sale_price' => 310000, 'payment_type' => 'due', 'down_payment' => 110000, 'promised_date' => $day(0), 'promise_note' => 'After collecting rent']],
            [['Farhana Yesmin', '1994123400099', '01712340009', null, 'Narayanganj'],
                ['auto_rickshaw', 'DC-2009', 'DE-2009', 'Mahindra', 'Alfa Auto', 2020, 'Green', 235, 'CNG', 'DHAKA METRO-THA-13-2009', 210000],
                ['sale_date' => $day(-7), 'sale_price' => 255000, 'payment_type' => 'due', 'down_payment' => 90000, 'promised_date' => $day(0), 'promise_note' => 'Promised by phone']],
        ];

        foreach ($rows as [$c, $v, $sale]) {
            if (Vehicle::where('chassis_no', $v[1])->exists()) {
                continue;
            }

            $customer = Customer::firstOrCreate(['phone' => $c[2]], ['name' => $c[0], 'nid' => $c[1], 'alt_phone' => $c[3], 'address' => $c[4]]);

            $vehicle = $purchases->create([
                'vehicle_type' => $v[0], 'chassis_no' => $v[1], 'engine_no' => $v[2], 'brand' => $v[3], 'model' => $v[4], 'year' => $v[5], 'color' => $v[6],
                'cc' => $v[7], 'fuel_type' => $v[8], 'registration_no' => $v[9], 'registration_date' => today()->subYears(2)->toDateString(),
                'tax_token_expiry' => today()->addMonths(8)->toDateString(), 'fitness_expiry' => today()->addMonths(5)->toDateString(),
                'insurance_expiry' => today()->addMonths(9)->toDateString(), 'papers_received' => true, 'mileage' => 12000,
                'source' => 'supplier', 'supplier_id' => $supplier->id, 'purchase_date' => today()->subDays(60)->toDateString(),
                'price' => $v[10], 'paid_amount' => $v[10], 'payment_method' => 'bank',
            ], $owner)->vehicle;

            $sales->create(['vehicle_id' => $vehicle->id, 'customer_id' => $customer->id] + $sale, $owner);
        }
    }
}
