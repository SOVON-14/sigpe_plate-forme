<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-ID');
        if ($correlationId === null || ! Str::isUuid($correlationId)) {
            $correlationId = (string) Str::uuid7();
        }

        $requestId = $request->header('X-Request-ID');
        if ($requestId === null || ! Str::isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
        }

        $request->headers->set('X-Correlation-ID', $correlationId);
        $request->headers->set('X-Request-ID', $requestId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
