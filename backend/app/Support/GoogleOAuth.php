<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Google sign-in and sign-up: trades the one-time code from Google's popup for the person's verified email. */
class GoogleOAuth
{
    /**
     * The verified person behind a code from Google's popup, using the client ID and secret the super admin saved.
     *
     * @return array{email: string, name: string}
     */
    public static function person(string $code): array
    {
        if (! PlatformSettings::googleEnabled()) {
            throw ValidationException::withMessages(['email' => ['Google sign-in is not turned on.']]);
        }

        $clientId = PlatformSettings::get('google.client_id');
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => PlatformSettings::get('google.client_secret'),
            'redirect_uri' => 'postmessage', // the code came from Google's popup, not a redirect
            'grant_type' => 'authorization_code',
        ]);

        // the ID token comes straight from Google over HTTPS in answer to our secret, so its claims can be read without checking its signature
        $claims = $response->successful() ? self::jwtClaims((string) $response->json('id_token')) : null;

        if (! $claims || ($claims['aud'] ?? null) !== $clientId || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw ValidationException::withMessages(['email' => ['Google sign-in failed. Please try again.']]);
        }

        if (empty($claims['email']) || ! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['email' => ['Your Google account email is not verified.']]);
        }

        return ['email' => $claims['email'], 'name' => $claims['name'] ?? strstr($claims['email'], '@', true)];
    }

    /** @return array<string,mixed>|null the payload of a JWT, or null when it is not one */
    private static function jwtClaims(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($claims) ? $claims : null;
    }
}
