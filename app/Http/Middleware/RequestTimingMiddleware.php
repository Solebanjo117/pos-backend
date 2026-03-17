<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestTimingMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);
        $response = $next($request);
        $duration = round((microtime(true) - $start) * 1000, 2);

        if ($duration > 500) {
            Log::warning('slow_request', [
                'method' => $request->method(),
                'uri' => $request->path(),
                'duration' => $duration,
                'user_id' => $request->user()?->id,
            ]);
        }

        $response->headers->set('X-Response-Time', $duration . 'ms');

        return $response;
    }
}
