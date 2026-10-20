<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Converting files that are already on this computer means reading any file on it
 * (and offering the result for download). That is fine from a browser on the same
 * computer, or when the app has a password; it is not offered to everyone on the
 * network otherwise. Uploading files from the browser works everywhere.
 */
class LocalFileAccess
{
    public const DENIED = 'Files on this computer can only be converted from a browser on the computer running AudioFetch, or when the app has a password (APP_PASSWORD). Upload the files instead.';

    public static function allowed(Request $request): bool
    {
        return in_array($request->ip(), ['127.0.0.1', '::1'], true) || AppLock::enabled();
    }
}
