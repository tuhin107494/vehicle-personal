<?php

namespace Database\Seeders;

use App\Models\Investor;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Demo vehicles sold to customers (cash, due and installment), each customer with NID and two mobiles.
 * Safe to run more than once: a customer or vehicle that already exists is skipped.
 * Run with: php artisan db:seed --class=DemoCustomerSalesSeeder
 */
class DemoCustomerSalesSeeder extends Seeder
{
    public function run(PurchaseService $purchases, SaleService $sales): void
    {
        $owner = User::where('role', 'shop_owner')->firstOrFail();
        Auth::setUser($owner); // so rows are stamped with the shop

        $supplier = Supplier::firstOrCreate(['phone' => '01711000001'], ['name' => 'Karim Motors']);
        $investor = Investor::firstOrCreate(['name' => 'Nasir Capital'], ['phone' => '01511000005', 'opening_balance' => 0]);
        $day = fn (int $offset) => today()->addDays($offset)->toDateString();

        // [customer, nid, mobile, alt mobile, address], [type, chassis, engine, brand, model, year, colour, cc, fuel, reg no, cost], sale
        $rows = [
            [['Abdul Karim', '1985123400011', '01712340001', '01812340001', 'Mirpur 10, Dhaka'],
                ['cng', 'DC-2001', 'DE-2001', 'Bajaj', 'RE Compact', 2022, 'Green', 200, 'CNG', 'DHAKA METRO-THA-11-2001', 290000],
                ['sale_date' => $day(-12), 'sale_price' => 320000, 'payment_type' => 'due', 'down_payment' => 120000, 'promised_date' => $day(0)]],
            [['Rina Begum', '1992123400022', '01712340002', null, 'Uttara Sector 7, Dhaka'],
                ['motorcycle', 'DC-2002', 'DE-2002', 'Yamaha', 'FZ-S V3', 2023, 'Black', 149, 'Octane', 'DHAKA METRO-LA-15-2002', 185000],
                ['sale_date' => $day(-20), 'sale_price' => 215000, 'payment_type' => 'cash', 'down_payment' => 215000]],
            [['Shafiqul Islam', '1979123400033', '01712340003', '01912340003', 'Jatrabari, Dhaka'],
                ['auto_rickshaw', 'DC-2003', 'DE-2003', 'Mahindra', 'Alfa Auto', 2021, 'Green', 235, 'CNG', 'DHAKA METRO-THA-13-2003', 240000],
                ['sale_date' => $day(-28), 'sale_price' => 300000, 'payment_type' => 'installment', 'down_payment' => 60000, 'investor_id' => $investor->id,
                    'installment_count' => 6, 'first_due_date' => $day(0), 'interest_rate' => 10, 'investor_share_percent' => 50]],
            [['Nasrin Akter', '1990123400044', '01712340004', '01612340004', 'Dhanmondi 27, Dhaka'],
                ['car', 'DC-2004', 'DE-2004', 'Toyota', 'Axio', 2019, 'Maroon', 1500, 'Octane', 'DHAKA METRO-GA-22-2004', 1200000],
                ['sale_date' => $day(-9), 'sale_price' => 1450000, 'payment_type' => 'installment', 'down_payment' => 450000, 'investor_id' => $investor->id,
                    'installment_count' => 12, 'first_due_date' => $day(0), 'interest_rate' => 8, 'investor_share_percent' => 40]],
            [['Mizanur Rahman', '1988123400055', '01712340005', '01812340055', 'Mohammadpur, Dhaka'],
                ['motorcycle', 'DC-2005', 'DE-2005', 'Honda', 'CBR 150R', 2022, 'Blue', 149, 'Octane', 'DHAKA METRO-LA-17-2005', 230000],
                ['sale_date' => $day(-6), 'sale_price' => 280000, 'payment_type' => 'due', 'down_payment' => 80000, 'promised_date' => $day(0)]],
            [['Tahmina Khatun', '1995123400066', '01712340006', '01912340066', 'Savar, Dhaka'],
                ['cng', 'DC-2006', 'DE-2006', 'Bajaj', 'RE Compact', 2020, 'Green', 200, 'CNG', 'DHAKA METRO-THA-11-2006', 250000],
                ['sale_date' => $day(-60), 'sale_price' => 300000, 'payment_type' => 'installment', 'down_payment' => 50000, 'investor_id' => $investor->id,
                    'installment_count' => 5, 'first_due_date' => $day(-15), 'interest_rate' => 10, 'investor_share_percent' => 50]],
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
                'source' => 'supplier', 'supplier_id' => $supplier->id, 'purchase_date' => today()->subDays(75)->toDateString(),
                'price' => $v[10], 'paid_amount' => $v[10], 'payment_method' => 'bank',
            ], $owner)->vehicle;

            $sales->create(['vehicle_id' => $vehicle->id, 'customer_id' => $customer->id] + $sale, $owner);
        }
    }
}
