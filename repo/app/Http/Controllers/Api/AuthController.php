<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',      // lowercase
                'regex:/[A-Z]/',      // uppercase
                'regex:/[0-9]/',      // digit
                'regex:/[@$!%*?&#^]/', // special char
                'confirmed',
            ],
            'phone' => 'nullable|string|max:20',
        ]);

        // Force role to general_user — privileged roles must be assigned by admin only
        $validated['role'] = 'general_user';

        $user = User::create($validated);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully.',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $lockoutKey = 'login_attempts:' . $request->email;
        $lockoutUntilKey = 'login_lockout:' . $request->email;

        // Check if locked out
        if (Cache::has($lockoutUntilKey)) {
            $lockoutUntil = Cache::get($lockoutUntilKey);
            $minutes = now()->diffInMinutes($lockoutUntil, false);
            if ($minutes > 0) {
                return response()->json([
                    'message' => "Account locked. Try again in {$minutes} minutes.",
                ], 429);
            }
            Cache::forget($lockoutKey);
            Cache::forget($lockoutUntilKey);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Increment failed attempts
            $attempts = Cache::increment($lockoutKey);
            if ($attempts === 1) {
                Cache::put($lockoutKey, 1, now()->addMinutes(15));
            }

            $maxAttempts = config('auth.lockout.max_attempts', 10);
            if ($attempts >= $maxAttempts) {
                Cache::put($lockoutUntilKey, now()->addMinutes(
                    config('auth.lockout.lockout_minutes', 15)
                ), now()->addMinutes(config('auth.lockout.lockout_minutes', 15)));

                return response()->json([
                    'message' => 'Account locked due to too many failed attempts. Try again in 15 minutes.',
                ], 429);
            }

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->disabled) {
            return response()->json([
                'message' => 'Account is disabled.',
            ], 403);
        }

        // Clear failed attempts on success
        Cache::forget($lockoutKey);
        Cache::forget($lockoutUntilKey);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
            ],
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'role' => $user->role,
                'disabled' => $user->disabled,
            ],
        ]);
    }
}
