<?php

namespace Database\Seeders;

use App\Models\Investor;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/** Sample data for trying the app: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(PurchaseService $purchases, SaleService $sales, PaymentService $payments): void
    {
        $admin = User::where('role', 'shop_owner')->firstOrFail();
        Auth::setUser($admin); // so rows are stamped with the shop

        $seller = Supplier::create(['name' => 'Karim Motors', 'phone' => '01711000001']);
        foreach ([
            ['Hasan Auto Traders', '01711000011'], ['Mizan Bike Centre', '01711000012'], ['Dhaka Used Motors', '01711000013'],
            ['Rafiq Auto House', '01711000014'], ['Sky Motors', '01711000015'], ['Alam Bike Corner', '01711000016'],
            ['Nur Auto Mart', '01711000017'], ['Green Wheels', '01711000018'], ['Salam Motors', '01711000019'],
        ] as [$name, $phone]) {
            Supplier::create(['name' => $name, 'phone' => $phone]);
        }
        $buyers = collect([
            ['Rahim Uddin', '01811000002'],
            ['Sadia Akter', '01911000003'],
            ['Jamal Hossain', '01611000004'],
        ])->map(fn ($b) => Customer::create(['name' => $b[0], 'phone' => $b[1]]));
        $investor = Investor::create(['name' => 'Nasir Capital', 'phone' => '01511000005', 'opening_balance' => 0]);

        $vehicles = collect([
            ['CH-1001', 'EN-1001', 'cng', 'Bajaj', 'RE Compact CNG', 2021, 350000],
            ['CH-1002', 'EN-1002', 'auto_rickshaw', 'Mahindra', 'Alfa Auto', 2020, 280000],
            ['CH-1003', 'EN-1003', 'motorcycle', 'Honda', 'CB Hornet 160R', 2022, 210000],
            ['CH-1004', 'EN-1004', 'motorcycle', 'Yamaha', 'R15 V3', 2021, 330000],
        ])->map(fn ($v) => $purchases->create([
            'chassis_no' => $v[0], 'engine_no' => $v[1], 'vehicle_type' => $v[2], 'brand' => $v[3], 'model' => $v[4], 'year' => $v[5],
            'supplier_id' => $seller->id, 'purchase_date' => today()->subDays(40)->toDateString(), 'price' => $v[6], 'paid_amount' => $v[6],
        ], $admin)->vehicle);

        $sales->create([
            'vehicle_id' => $vehicles[0]->id, 'customer_id' => $buyers[0]->id, 'sale_date' => today()->subDays(20)->toDateString(),
            'sale_price' => 400000, 'payment_type' => 'cash', 'down_payment' => 400000,
        ], $admin);

        $due = $sales->create([
            'vehicle_id' => $vehicles[1]->id, 'customer_id' => $buyers[1]->id, 'sale_date' => today()->subDays(10)->toDateString(),
            'sale_price' => 330000, 'payment_type' => 'due', 'down_payment' => 180000, 'promised_date' => today()->addDays(2)->toDateString(),
        ], $admin);

        $installment = $sales->create([
            'vehicle_id' => $vehicles[2]->id, 'customer_id' => $buyers[2]->id, 'sale_date' => today()->subDays(35)->toDateString(),
            'sale_price' => 260000, 'payment_type' => 'installment', 'down_payment' => 60000, 'investor_id' => $investor->id,
            'installment_count' => 6, 'first_due_date' => today()->subDays(5)->toDateString(), 'interest_rate' => 12, 'investor_share_percent' => 60,
        ], $admin);

        $payments->collect($installment, ['amount' => 15000], $admin);

        $this->call(DemoCustomerSalesSeeder::class);
    }
}
