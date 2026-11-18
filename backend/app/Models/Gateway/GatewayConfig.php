<?php

namespace App\Models\Gateway;

use Illuminate\Database\Eloquent\Model;

/**
 * One payment gateway as the super admin set it up. Credentials are two encrypted JSON blobs (sandbox and live),
 * hidden from every response: the API only ever says whether a secret is set.
 *
 * @property array<string, mixed>|null $sandbox_credentials
 * @property array<string, mixed>|null $live_credentials
 */
class GatewayConfig extends Model
{
    protected $fillable = [
        'code',
        'display_name',
        'logo_path',
        'description',
        'is_active',
        'sort_order',
        'active_mode',
        'sandbox_credentials',
        'live_credentials',
        'min_amount_paisa',
        'max_amount_paisa',
        'fee_percent',
        'fee_fixed_paisa',
        'webhook_ips',
    ];

    protected $hidden = ['sandbox_credentials', 'live_credentials'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'sort_order' => 'integer',
            'sandbox_credentials' => 'encrypted:array', 'live_credentials' => 'encrypted:array',
            'min_amount_paisa' => 'integer', 'max_amount_paisa' => 'integer', 'fee_percent' => 'float', 'fee_fixed_paisa' => 'integer',
        ];
    }

    /** The credentials of the mode that is switched on. */
    public function credentials(): array
    {
        return ($this->active_mode === 'live' ? $this->live_credentials : $this->sandbox_credentials) ?? [];
    }

    /** Whether an amount (paisa) is within this gateway's limits. */
    public function accepts(int $paisa): bool
    {
        return ($this->min_amount_paisa === null || $paisa >= $this->min_amount_paisa)
            && ($this->max_amount_paisa === null || $paisa <= $this->max_amount_paisa);
    }

    /** The fee the platform pays the gateway on an amount, in paisa. */
    public function feeFor(int $paisa): int
    {
        return (int) round($paisa * $this->fee_percent / 100) + $this->fee_fixed_paisa;
    }

    /** Whether a webhook may come from this address: any when no list is kept. */
    public function allowsWebhookFrom(?string $ip): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', (string) $this->webhook_ips)));

        return $allowed === [] || in_array($ip, $allowed, true);
    }
}
