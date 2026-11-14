<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }

        try {
            $claims = AuthenticateUser::claims($request);
        } catch (DomainException) {
            return $next($request);
        }

        $subject = $claims['sub'];
        $route = $request->path();
        $method = $request->method();
        $hash = hash('sha256', $method.'|'.$route.'|'.$request->getContent());

        $existing = IdempotencyKey::where('key', $key)
            ->where('subject', $subject)
            ->where('method', $method)
            ->where('route', $route)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                throw new DomainException(
                    'IDEMPOTENCY_CONFLICT',
                    'La clé d’idempotence a déjà été utilisée avec une requête différente.',
                );
            }

            return response()->json($existing->response_body, $existing->response_status)
                ->header('Idempotent-Replay', 'true')
                ->header('X-Correlation-ID', $request->header('X-Correlation-ID', ''));
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500 && str_contains($response->headers->get('content-type', ''), 'json')) {
            IdempotencyKey::create([
                'key' => $key,
                'subject' => $subject,
                'method' => $method,
                'route' => $route,
                'request_hash' => $hash,
                'response_status' => $response->getStatusCode(),
                'response_body' => json_decode($response->getContent(), true),
            ]);
        }

        return $response;
    }
}
