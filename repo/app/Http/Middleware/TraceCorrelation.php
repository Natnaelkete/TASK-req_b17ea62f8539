<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceCorrelation
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-Id', Str::uuid()->toString());

        // Store trace ID in app container for access across the request lifecycle
        app()->instance('trace_id', $traceId);

        // Push trace_id into the shared log context so all log entries include it
        Log::shareContext([
            'trace_id' => $traceId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        $response = $next($request);

        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
