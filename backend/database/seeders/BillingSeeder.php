<?php

namespace Database\Seeders;

use App\Models\Billing\Feature;
use App\Models\Billing\Plan;
use App\Support\FeatureCatalog;
use Illuminate\Database\Seeder;

/**
 * The feature keys the code checks, and three sample plans to start from. Safe to re-run: it never changes a plan the
 * super admin has edited (plans are matched by slug, features by key, and only created when missing).
 */
class BillingSeeder extends Seeder
{
    /** slug => [name, interval, price in taka, trial days, sort, feature values] */
    private const PLANS = [
        'starter' => ['Starter', 'monthly', 500, 14, 1, ['max_staff' => 2, 'max_vehicles_in_stock' => 30, 'max_sales_per_month' => 50, 'investors' => 0, 'installments' => 1, 'bookings' => 1, 'reports' => 0, 'customer_import' => 0]],
        'business' => ['Business', 'monthly', 1500, 14, 2, ['max_staff' => 10, 'max_vehicles_in_stock' => 300, 'max_sales_per_month' => null, 'investors' => 1, 'installments' => 1, 'bookings' => 1, 'reports' => 1, 'customer_import' => 1]],
        'business-yearly' => ['Business (yearly)', 'yearly', 15000, 14, 3, ['max_staff' => 10, 'max_vehicles_in_stock' => 300, 'max_sales_per_month' => null, 'investors' => 1, 'installments' => 1, 'bookings' => 1, 'reports' => 1, 'customer_import' => 1]],
    ];

    public function run(): void
    {
        foreach (FeatureCatalog::all() as [$key, $type, $description]) {
            Feature::updateOrCreate(['key' => $key], ['type' => $type, 'description' => $description]);
        }

        $features = Feature::pluck('id', 'key');

        foreach (self::PLANS as $slug => [$name, $interval, $taka, $trial, $sort, $values]) {
            if (Plan::where('slug', $slug)->exists()) {
                continue;
            }

            $plan = Plan::create(['name' => $name, 'slug' => $slug, 'price_paisa' => $taka * 100, 'billing_interval' => $interval, 'trial_days' => $trial, 'sort_order' => $sort]);
            $plan->features()->sync(collect($values)->mapWithKeys(fn ($v, $key) => [$features[$key] => ['value' => $v === null ? null : (string) $v]])->all());
        }
    }
}
