<?php

namespace App\Http\Middleware;

use App\Support\AppLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the API until the browser has unlocked the app (only when APP_PASSWORD is set)
 */
class RequireAppPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if (AppLock::allows($request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This app is locked. Enter the password to continue.',
            'locked' => true,
        ], 401);
    }
}
