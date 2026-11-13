<?php

namespace Database\Seeders;

use App\Models\Gateway\GatewayConfig;
use Illuminate\Database\Seeder;

/**
 * One row per gateway the code knows, inactive and in sandbox mode with no credentials: the super admin fills them in
 * from the admin panel. Safe to re-run: an existing row is left exactly as it is.
 */
class GatewayConfigSeeder extends Seeder
{
    /** code => [display name, sort, description shown at checkout] */
    public const GATEWAYS = [
        'bkash' => ['bKash', 1, 'Pay with your bKash wallet.'],
        'nagad' => ['Nagad', 2, 'Pay with your Nagad wallet.'],
        'sslcommerz' => ['Card / other wallets', 3, 'Visa, Mastercard, Rocket, Upay and more, through SSLCommerz.'],
        'manual' => ['Bank transfer', 4, 'Transfer to our bank account and submit the reference. We confirm within one working day.'],
    ];

    public function run(): void
    {
        foreach (self::GATEWAYS as $code => [$name, $sort, $description]) {
            GatewayConfig::firstOrCreate(['code' => $code], ['display_name' => $name, 'sort_order' => $sort, 'description' => $description, 'is_active' => false, 'active_mode' => 'sandbox']);
        }
    }
}
