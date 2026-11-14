<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ProblemResponse
{
    public static function make(
        int $status,
        string $title,
        string $detail,
        ?string $code = null,
        ?string $correlationId = null,
        array $extra = [],
    ): JsonResponse {
        $body = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ] + $extra;
        if ($code !== null) {
            $body['code'] = $code;
        }
        if ($correlationId !== null) {
            $body['correlation_id'] = $correlationId;
        }

        return response()
            ->json($body, $status)
            ->header('Content-Type', 'application/problem+json')
            ->header('X-Correlation-ID', $correlationId ?? '');
    }

    public static function fromStatus(int $status, ?string $code, string $detail, ?string $correlationId = null): JsonResponse
    {
        $titles = [
            400 => 'Requête invalide',
            401 => 'Non authentifié',
            403 => 'Non autorisé',
            404 => 'Ressource introuvable',
            409 => 'Conflit',
            422 => 'Erreur de validation',
            429 => 'Limitation de débit',
            500 => 'Erreur interne',
        ];

        return self::make($status, $titles[$status] ?? 'Erreur', $detail, $code, $correlationId);
    }
}
