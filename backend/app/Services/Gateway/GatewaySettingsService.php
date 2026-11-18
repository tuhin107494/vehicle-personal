<?php

namespace App\Services\Gateway;

use App\Exceptions\BusinessRuleException;
use App\Models\Gateway\AdminAuditLog;
use App\Models\Gateway\GatewayConfig;
use App\Models\User;
use App\Services\Gateway\Data\CredentialField;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The super admin's gateway settings. Secrets are write-only: the API shows a masked tail, a blank field keeps the saved
 * value, and every change is audited without secret values. Switching to live mode asks for the admin's password again.
 */
class GatewaySettingsService
{
    public const GENERAL_FIELDS = ['display_name', 'description', 'is_active', 'sort_order', 'active_mode', 'min_amount_paisa', 'max_amount_paisa', 'fee_percent', 'fee_fixed_paisa', 'webhook_ips'];

    /** What the configure page shows for one gateway: general fields, the schema, masked credentials per mode, and the URLs to paste into the merchant portal. */
    public function describe(GatewayConfig $config): array
    {
        $class = GatewayRegistry::classFor($config->code);

        return [
            'schema' => $class::credentialSchema(),
            'sandbox' => $this->masked($class::fields(), $config->sandbox_credentials ?? []),
            'live' => $this->masked($class::fields(), $config->live_credentials ?? []),
            'configured' => ['sandbox' => $this->complete($class::fields(), $config->sandbox_credentials ?? []), 'live' => $this->complete($class::fields(), $config->live_credentials ?? [])],
            'urls' => $this->urls($config->code),
        ];
    }

    /** Whether the credentials of the active mode have every required field. */
    public function isConfigured(GatewayConfig $config): bool
    {
        return $this->complete(GatewayRegistry::classFor($config->code)::fields(), $config->credentials());
    }

    /**
     * Saves general fields and, per mode, the credential fields that were sent. A blank secret keeps the old one.
     *
     * @param  array{general?: array<string, mixed>, sandbox?: array<string, mixed>, live?: array<string, mixed>, password?: string}  $input
     */
    public function update(GatewayConfig $config, array $input, User $admin, ?string $ip = null): GatewayConfig
    {
        $class = GatewayRegistry::classFor($config->code);
        $fields = $class::fields();
        $general = array_intersect_key($input['general'] ?? [], array_flip(self::GENERAL_FIELDS));

        // going live is the one change that can move real money: the admin proves it is them
        if (($general['active_mode'] ?? null) === 'live' && $config->active_mode !== 'live') {
            if (blank($input['password'] ?? null) || ! Hash::check($input['password'], $admin->password)) {
                throw ValidationException::withMessages(['password' => 'Enter your password to switch this gateway to live mode.']);
            }
        }

        return DB::transaction(function () use ($config, $input, $general, $fields, $admin, $ip) {
            $config = GatewayConfig::lockForUpdate()->findOrFail($config->id);
            $changes = [];

            foreach ($general as $field => $value) {
                $before = $config->getAttribute($field);
                $config->setAttribute($field, $value);

                if ((string) $before !== (string) $value) {
                    $changes[] = ['field' => $field, 'from' => $before, 'to' => $value];
                }
            }

            foreach (['sandbox', 'live'] as $mode) {
                $sent = $input[$mode] ?? null;

                if (! is_array($sent)) {
                    continue;
                }

                $column = "{$mode}_credentials";
                $saved = $mode === 'live' ? ($config->live_credentials ?? []) : ($config->sandbox_credentials ?? []);
                $merged = $this->merge($fields, $saved, $sent);

                foreach ($fields as $field) {
                    if (($saved[$field->name] ?? null) === ($merged[$field->name] ?? null)) {
                        continue;
                    }
                    // a secret is listed by name only; its values never reach the audit log
                    $changes[] = $field->isSecret
                        ? ['field' => "$mode.{$field->name}", 'to' => '[changed]']
                        : ['field' => "$mode.{$field->name}", 'from' => $saved[$field->name] ?? null, 'to' => $merged[$field->name] ?? null];
                }

                $config->setAttribute($column, $merged);
            }

            $config->save();

            if ($changes) {
                $this->forgetTokens($config->code); // a cached grant token belongs to the old credentials
                AdminAuditLog::record($admin, in_array('active_mode', array_column($changes, 'field'), true) ? 'gateway.mode_changed' : 'gateway.updated', $config, $changes, $ip);
            }

            return $config->fresh();
        });
    }

    /**
     * Gateways the code supports that have no row yet, with the fields each one asks for, so the super admin can add them.
     *
     * @return list<array{code: string, schema: list<array<string, mixed>>}>
     */
    public function addable(): array
    {
        $added = GatewayConfig::pluck('code')->all();
        $out = [];

        foreach (GatewayRegistry::all() as $code => $class) {
            if (! in_array($code, $added, true)) {
                $out[] = ['code' => $code, 'schema' => $class::credentialSchema()];
            }
        }

        return $out;
    }

    /**
     * Adds a gateway the code supports: switched off and in sandbox mode, with the name and the credentials typed in.
     * The super admin switches it on from the list once Test connection works. Secrets are stored encrypted, as on update.
     *
     * @param  array{code: string, general?: array<string, mixed>, sandbox?: array<string, mixed>, live?: array<string, mixed>}  $input
     */
    public function create(array $input, User $admin, ?string $ip = null): GatewayConfig
    {
        return DB::transaction(function () use ($input, $admin, $ip) {
            if (GatewayConfig::where('code', $input['code'])->lockForUpdate()->exists()) {
                throw new BusinessRuleException('This gateway was already added. Open Configure to change it.');
            }

            $general = $input['general'] ?? [];
            $config = GatewayConfig::create([
                'code' => $input['code'],
                'display_name' => $general['display_name'],
                'description' => $general['description'] ?? null,
                'sort_order' => (int) (GatewayConfig::max('sort_order') ?? 0) + 1,
                'is_active' => false,
                'active_mode' => 'sandbox',
            ]);
            AdminAuditLog::record($admin, 'gateway.created', $config, [['field' => 'code', 'to' => $config->code]], $ip);

            // the credentials go through the same path as an edit: merged against the schema, secrets audited by name only
            return $this->update($config, ['sandbox' => $input['sandbox'] ?? [], 'live' => $input['live'] ?? []], $admin, $ip);
        });
    }

    /** Runs the gateway's own safe check with the saved credentials of the active mode. Returns the error message, or null when it worked. */
    public function testConnection(GatewayConfig $config, GatewayRegistry $registry): ?string
    {
        if (! $this->isConfigured($config)) {
            return 'Fill in every required field for the '.$config->active_mode.' mode first.';
        }

        try {
            $registry->make($config)->testConnection();

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage() ?: 'The gateway refused the connection.';
        }
    }

    /** Any token a gateway class cached for these credentials (e.g. a bKash grant token). */
    public function forgetTokens(string $code): void
    {
        foreach (['sandbox', 'live'] as $mode) {
            Cache::forget("gateway:{$code}:token:{$mode}");
            Cache::forget("gateway:{$code}:token:{$mode}:refresh"); // a refresh token belongs to the old credentials too
        }
    }

    /** @return array<string, string> the addresses to register at the gateway's merchant portal */
    public function urls(string $code): array
    {
        $api = rtrim(config('app.url'), '/').'/api/gateways/'.$code;

        return [
            'callback' => "$api/callback",
            'success' => "$api/callback?result=success",
            'fail' => "$api/callback?result=fail",
            'cancel' => "$api/callback?result=cancel",
            'webhook' => "$api/webhook",
        ];
    }

    /**
     * The saved values with each sent value on top. A blank secret keeps the saved one; a blank non-secret clears it.
     *
     * @param  list<CredentialField>  $fields
     */
    private function merge(array $fields, array $saved, array $sent): array
    {
        $out = $saved;

        foreach ($fields as $field) {
            if (! array_key_exists($field->name, $sent)) {
                continue;
            }

            $value = $sent[$field->name];

            if ($field->isSecret && blank($value)) {
                continue;
            }

            if ($field->type === 'textarea' && $field->isSecret && filled($value)) {
                $this->ensureLooksLikeKey($field, (string) $value);
            }

            $out[$field->name] = $field->type === 'boolean' ? (bool) $value : (blank($value) ? null : $value);
        }

        return $out;
    }

    /** An RSA key field must hold a PEM key, not an empty paste or a file path. */
    private function ensureLooksLikeKey(CredentialField $field, string $value): void
    {
        $trimmed = trim($value);
        $pem = str_starts_with($trimmed, '-----BEGIN') && str_contains($trimmed, '-----END');
        $bare = preg_match('#^[A-Za-z0-9+/=\s]{64,}$#', $trimmed) === 1; // some portals hand out the base64 body only

        if (! $pem && ! $bare) {
            throw new BusinessRuleException($field->label.' does not look like an RSA key. Paste the PEM text (BEGIN ... END) or its base64 body.');
        }
    }

    /** @param  list<CredentialField>  $fields */
    private function masked(array $fields, array $values): array
    {
        $out = [];

        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;
            $out[$field->name] = $field->isSecret
                ? (filled($value) ? '••••••••'.substr((string) $value, -4) : null)
                : $value;
        }

        return $out;
    }

    /** @param  list<CredentialField>  $fields */
    private function complete(array $fields, array $values): bool
    {
        foreach ($fields as $field) {
            if ($field->required && blank($values[$field->name] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
