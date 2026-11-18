<?php

namespace App\Models\Gateway;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only record of what went to and came from a gateway. Written by workers and webhooks with no signed-in
 * user, read by the super admin, so it has no shop scope; shop_id is kept for filtering.
 */
class GatewayLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['shop_id', 'attempt_id', 'gateway_code', 'direction', 'action', 'payload', 'http_status', 'ip'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'http_status' => 'integer', 'created_at' => 'datetime'];
    }

    /** Keys whose values are never written to a log, whatever a gateway sends back. */
    public const SECRET_KEYS = ['password', 'app_secret', 'app_key', 'store_passwd', 'store_password', 'id_token', 'refresh_token', 'authorization', 'x-app-key', 'merchant_private_key', 'private_key'];

    /** The payload with secret values blanked, at any depth. */
    public static function scrub(mixed $payload): mixed
    {
        if (! is_array($payload)) {
            return $payload;
        }

        $out = [];

        foreach ($payload as $key => $value) {
            $out[$key] = in_array(strtolower((string) $key), self::SECRET_KEYS, true) ? '[hidden]' : self::scrub($value);
        }

        return $out;
    }
}
