<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->disabled) {
            return response()->json(['message' => 'Account is disabled.'], 403);
        }

        if (!empty($roles) && !$user->hasAnyRole($roles)) {
            return response()->json(['message' => 'Insufficient permissions.'], 403);
        }

        return $next($request);
    }
}
