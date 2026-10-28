<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Platform-wide settings the super admin keeps: Google sign-in and the SMTP server mail goes out through. */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /** key => kind. Secrets are stored encrypted and never sent back to the browser. */
    public const KEYS = [
        'signup.enabled' => 'bool',
        'google.enabled' => 'bool',
        'google.client_id' => 'string',
        'google.client_secret' => 'secret',
        'smtp.enabled' => 'bool',
        'smtp.host' => 'string',
        'smtp.port' => 'int',
        'smtp.encryption' => 'string', // tls (STARTTLS, port 587) or ssl (port 465)
        'smtp.username' => 'string',
        'smtp.password' => 'secret',
        'smtp.from_address' => 'string',
        'smtp.from_name' => 'string',
    ];

    /** Values used until the super admin saves one. Sign-up is open by default: new shops still wait for approval. */
    private const DEFAULTS = ['signup.enabled' => true];

    public static function get(string $key): mixed
    {
        return self::values()[$key] ?? null;
    }

    /** What the settings page shows: every value except the secrets, which only say whether one is saved. */
    public static function forAdmin(): array
    {
        $out = [];

        foreach (self::KEYS as $key => $kind) {
            [$group, $name] = explode('.', $key, 2);
            $kind === 'secret'
                ? $out[$group][$name.'_set'] = filled(self::get($key))
                : $out[$group][$name] = self::get($key);
        }

        return $out;
    }

    /** Saves the given values. A blank secret keeps the saved one, so the page does not have to know it. */
    public static function save(array $input): void
    {
        // every key is saved together or none are
        DB::transaction(function () use ($input) {
            foreach (self::KEYS as $key => $kind) {
                [$group, $name] = explode('.', $key, 2);

                if (! array_key_exists($name, $input[$group] ?? [])) {
                    continue;
                }

                $value = $input[$group][$name];

                if ($kind === 'secret') {
                    if (blank($value)) {
                        continue;
                    }
                    $value = Crypt::encryptString($value);
                } elseif ($kind === 'bool') {
                    $value = $value ? '1' : '0';
                }

                blank($value)
                    ? PlatformSetting::where('key', $key)->delete()
                    : PlatformSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /** Google sign-in is offered only once it is switched on and both keys are saved. */
    public static function googleEnabled(): bool
    {
        return self::get('google.enabled') && filled(self::get('google.client_id')) && filled(self::get('google.client_secret'));
    }

    public static function smtpEnabled(): bool
    {
        return self::get('smtp.enabled') && filled(self::get('smtp.host'));
    }

    /** Points Laravel's mailer at the saved SMTP server. Without one switched on, the .env mail settings stay in charge. */
    public static function applyMail(): void
    {
        try {
            if (! self::smtpEnabled()) {
                return;
            }
        } catch (Throwable) {
            return; // no settings table yet (before the first migrate): keep the .env settings
        }

        $encryption = self::get('smtp.encryption');

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => self::get('smtp.host'),
            'mail.mailers.smtp.port' => self::get('smtp.port') ?: ($encryption === 'ssl' ? 465 : 587),
            // "smtps" connects over SSL from the start; plain "smtp" upgrades with STARTTLS when the server offers it
            'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => self::get('smtp.username'),
            'mail.mailers.smtp.password' => self::get('smtp.password'),
        ]);

        if (filled(self::get('smtp.from_address'))) {
            config(['mail.from.address' => self::get('smtp.from_address')]);
        }
        if (filled(self::get('smtp.from_name'))) {
            config(['mail.from.name' => self::get('smtp.from_name')]);
        }
    }

    /** @return array<string,mixed> every saved value, decrypted and cast */
    private static function values(): array
    {
        $raw = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::pluck('value', 'key')->all());
        $out = [];

        foreach (self::KEYS as $key => $kind) {
            $value = $raw[$key] ?? null;
            $out[$key] = match (true) {
                $value === null => self::DEFAULTS[$key] ?? ($kind === 'bool' ? false : null),
                $kind === 'bool' => $value === '1',
                $kind === 'int' => (int) $value,
                $kind === 'secret' => self::decrypt($value),
                default => $value,
            };
        }

        return $out;
    }

    /** A secret saved under an earlier APP_KEY cannot be read any more; it counts as not saved. */
    private static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
}
