<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrustFrontendProxyAuth
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $secret = config('services.frontend_proxy.secret');
        $providedSecret = $request->header('X-Frontend-Proxy-Secret');
        $userId = $request->header('X-Frontend-User-Id');

        if (
            is_string($secret) &&
            $secret !== '' &&
            is_string($providedSecret) &&
            hash_equals($secret, $providedSecret) &&
            is_string($userId) &&
            ctype_digit($userId)
        ) {
            Auth::onceUsingId((int) $userId);
        }

        if ($mode === 'required' && $request->user() === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
