<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public const ATTRIBUTE = 'sigpe.correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get('X-Correlation-ID');
        $requestId = $request->headers->get('X-Request-ID');

        if (! $this->isUuid($correlationId)) {
            $correlationId = (string) Str::uuid7();
        }
        if (! $this->isUuid($requestId)) {
            $requestId = (string) Str::uuid7();
        }

        $request->headers->set('X-Correlation-ID', $correlationId);
        $request->headers->set('X-Request-ID', $requestId);
        $request->attributes->set(self::ATTRIBUTE, $correlationId);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    private function isUuid(?string $value): bool
    {
        return $value !== null
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }
}
