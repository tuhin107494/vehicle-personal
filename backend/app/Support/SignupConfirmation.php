<?php

namespace App\Support;

use App\Models\User;

/**
 * The "confirm your email" link sent after an email-and-password sign-up. Opening it proves the email is the person's
 * own, opens their shop and signs them in.
 *
 * The link carries a signature (HMAC with the app key) over the user, their email and the expiry, so it cannot be made
 * up or changed. It works once: confirming sets email_verified_at, and a confirmed account is refused.
 */
class SignupConfirmation
{
    public const HOURS = 24;

    /** Signed-up shops whose owner never confirmed are deleted after this many days, which frees the email again. */
    public const PRUNE_AFTER_DAYS = 7;

    public static function url(User $user): string
    {
        $expires = now()->addHours(self::HOURS)->getTimestamp();

        return rtrim(config('app.frontend_url'), '/').'/verify-email?'.http_build_query([
            'id' => $user->id,
            'expires' => $expires,
            'token' => self::sign($user, $expires),
        ]);
    }

    /** The unconfirmed sign-up this link is for, or null when the link is made up, changed, expired or already used. */
    public static function user(int $id, int $expires, string $token): ?User
    {
        if ($expires < now()->getTimestamp()) {
            return null;
        }

        $user = User::with('shop')->find($id);

        if (! $user || ! hash_equals(self::sign($user, $expires), $token) || ! self::awaiting($user)) {
            return null;
        }

        return $user;
    }

    /** A shop owner who signed up with email and password and has not opened the link yet. */
    public static function awaiting(User $user): bool
    {
        return $user->role === 'shop_owner' && $user->email_verified_at === null && (bool) $user->shop?->isPending();
    }

    private static function sign(User $user, int $expires): string
    {
        return hash_hmac('sha256', "signup-confirm|{$user->id}|{$user->email}|{$expires}", (string) config('app.key'));
    }
}
