<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ProblemResponse
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function make(
        int $status,
        string $title,
        string $detail,
        string $code,
        ?string $correlationId = null,
        array $extra = [],
    ): JsonResponse {
        $body = array_merge([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'correlation_id' => $correlationId,
        ], $extra);

        return response()->json($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    public static function fromStatus(int $status, string $code, string $detail, ?string $correlationId = null): JsonResponse
    {
        $title = match ($status) {
            400 => 'Requête invalide',
            401 => 'Non authentifié',
            403 => 'Accès refusé',
            404 => 'Ressource introuvable',
            409 => 'Conflit',
            422 => 'Erreur de validation',
            429 => 'Trop de requêtes',
            503 => 'Service indisponible',
            default => 'Erreur',
        };

        return self::make($status, $title, $detail, $code, $correlationId);
    }
}
