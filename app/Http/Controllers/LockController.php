<?php

namespace App\Http\Controllers;

use App\Support\AppLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LockController extends Controller
{
    /**
     * Whether a password is set and whether this browser has unlocked the app
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => AppLock::enabled(),
            'unlocked' => AppLock::allows($request),
        ]);
    }

    public function unlock(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|max:255']);

        if (!AppLock::check($data['password'])) {
            throw ValidationException::withMessages(['password' => 'Wrong password.']);
        }

        return response()->json(['unlocked' => true])->withCookie(AppLock::unlockCookie($request));
    }

    public function lock(): JsonResponse
    {
        return response()->json(['unlocked' => !AppLock::enabled()])->withCookie(AppLock::lockCookie());
    }
}
