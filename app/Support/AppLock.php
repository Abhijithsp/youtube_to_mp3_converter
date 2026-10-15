<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Optional password for running the app on a server (APP_PASSWORD in .env).
 * Without a password the app stays open, as it is for local use.
 *
 * Unlocking sets a long-lived cookie holding an HMAC of the password, so
 * changing the password (or APP_KEY) locks every device again.
 */
class AppLock
{
    public const COOKIE = 'yc_unlock';

    private const DAYS = 30;

    public static function enabled(): bool
    {
        return filled(static::password());
    }

    public static function allows(Request $request): bool
    {
        return !static::enabled() || hash_equals(static::token(), (string) $request->cookie(static::COOKIE));
    }

    /**
     * APP_PASSWORD may be plain text or a bcrypt/argon hash (php artisan app:hash-password)
     */
    public static function check(string $given): bool
    {
        $password = static::password();

        if (!filled($password)) {
            return true;
        }

        return Hash::isHashed($password) ? Hash::check($given, $password) : hash_equals($password, $given);
    }

    public static function unlockCookie(Request $request): Cookie
    {
        return cookie(static::COOKIE, static::token(), 60 * 24 * self::DAYS, '/', null, $request->secure(), true, false, 'lax');
    }

    public static function lockCookie(): Cookie
    {
        return cookie()->forget(static::COOKIE);
    }

    private static function token(): string
    {
        return hash_hmac('sha256', 'unlock|' . static::password(), (string) config('app.key'));
    }

    private static function password(): ?string
    {
        $password = config('youtube-converter.password');

        return is_string($password) && $password !== '' ? $password : null;
    }
}
