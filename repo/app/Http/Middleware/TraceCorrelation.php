<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceCorrelation
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Trace-Id', Str::uuid()->toString());

        // Store trace ID for logging
        app()->instance('trace_id', $traceId);

        $response = $next($request);

        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}
