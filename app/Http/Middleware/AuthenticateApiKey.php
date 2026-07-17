<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticate API requests via Bearer token (API key).
 *
 * Looks for 'Authorization: Bearer rk_xxx' header, finds matching
 * ApiKey record, sets auth user + updates last_used_at.
 *
 * Does NOT replace session auth — only used for /api/* routes.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Missing Authorization header. Send "Authorization: Bearer rk_xxx"'], 401);
        }

        $apiKey = ApiKey::where('token', $token)->first();

        if (!$apiKey) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        // Update last used timestamp (throttled — only update if > 5 min old)
        if (!$apiKey->last_used_at || $apiKey->last_used_at->diffInMinutes(now()) > 5) {
            $apiKey->update(['last_used_at' => now()]);
        }

        // Set the authenticated user for the request
        auth()->setUser($apiKey->user);

        return $next($request);
    }
}
