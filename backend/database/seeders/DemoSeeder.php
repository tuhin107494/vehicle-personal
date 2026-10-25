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

        // ten more bikes and three-wheelers left in stock, bought from the other suppliers (two still part-unpaid)
        $suppliers = Supplier::where('id', '!=', $seller->id)->orderBy('id')->get();
        foreach ([
            ['CH-2001', 'EN-2001', 'motorcycle', 'Honda', 'CB Shine 125', 2022, 150000, 150000],
            ['CH-2002', 'EN-2002', 'motorcycle', 'Yamaha', 'FZS V3', 2021, 260000, 260000],
            ['CH-2003', 'EN-2003', 'motorcycle', 'Suzuki', 'Gixxer SF', 2022, 280000, 200000],
            ['CH-2004', 'EN-2004', 'motorcycle', 'Bajaj', 'Pulsar 150', 2020, 175000, 175000],
            ['CH-2005', 'EN-2005', 'motorcycle', 'TVS', 'Apache RTR 160', 2021, 190000, 190000],
            ['CH-2006', 'EN-2006', 'cng', 'Bajaj', 'RE Compact CNG', 2019, 320000, 320000],
            ['CH-2007', 'EN-2007', 'cng', 'Piaggio', 'Ape City', 2020, 300000, 220000],
            ['CH-2008', 'EN-2008', 'auto_rickshaw', 'Mahindra', 'Alfa Auto', 2021, 270000, 270000],
            ['CH-2009', 'EN-2009', 'auto_rickshaw', 'Atul', 'Gem Auto', 2019, 240000, 240000],
            ['CH-2010', 'EN-2010', 'motorcycle', 'Hero', 'Hunk 150R', 2022, 165000, 165000],
        ] as $i => $v) {
            $purchases->create([
                'chassis_no' => $v[0], 'engine_no' => $v[1], 'vehicle_type' => $v[2], 'brand' => $v[3], 'model' => $v[4], 'year' => $v[5],
                'supplier_id' => $suppliers[$i % $suppliers->count()]->id, 'purchase_date' => today()->subDays(15 - $i)->toDateString(), 'price' => $v[6], 'paid_amount' => $v[7],
            ], $admin);
        }

        $sales->create([
            'vehicle_id' => $vehicles[0]->id, 'customer_id' => $buyers[0]->id, 'sale_date' => today()->subDays(20)->toDateString(),
            'sale_price' => 400000, 'payment_type' => 'cash', 'down_payment' => 400000,
        ], $admin);

        $due = $sales->create([
            'vehicle_id' => $vehicles[1]->id, 'customer_id' => $buyers[1]->id, 'sale_date' => today()->subDays(10)->toDateString(),
            'sale_price' => 330000, 'payment_type' => 'due', 'down_payment' => 180000, 'promised_date' => today()->toDateString(),
        ], $admin);

        $installment = $sales->create([
            'vehicle_id' => $vehicles[2]->id, 'customer_id' => $buyers[2]->id, 'sale_date' => today()->subDays(35)->toDateString(),
            'sale_price' => 260000, 'payment_type' => 'installment', 'down_payment' => 60000, 'investor_id' => $investor->id,
            'installment_count' => 6, 'first_due_date' => today()->subDays(5)->toDateString(), 'interest_rate' => 12, 'investor_share_percent' => 60,
        ], $admin);

        $payments->collect($installment, ['amount' => 15000], $admin);

        $this->call(DemoCustomerSalesSeeder::class);

        // a guarantor for every sale that is not paid in full at once
        foreach (\App\Models\Sale::where('payment_type', '!=', 'cash')->whereNull('guarantor_phone')->get() as $sale) {
            $sale->update(['guarantor_phone' => '01719'.str_pad((string) $sale->id, 6, '0', STR_PAD_LEFT)]);
        }
    }
}
