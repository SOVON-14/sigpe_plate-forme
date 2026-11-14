<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP vers Asset Service (API Gateway).
 * Authentification service-to-service : client credentials Keycloak
 * (compte de service Movement avec la permission asset.rattachement.confirm).
 */
class AssetGateway
{
    public function confirmRattachement(
        string $assetId,
        string $institutionId,
        ?string $organizationalUnitId,
        string $idempotencyKey,
        ?string $correlationId = null,
    ): void {
        $baseUrl = rtrim((string) config('services.asset.url'), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('ASSET_SERVICE_URL non configure.');
        }

        $response = Http::withToken($this->serviceToken())
            ->acceptJson()
            ->asJson()
            ->withHeaders(array_filter([
                'Idempotency-Key' => $idempotencyKey,
                'X-Correlation-ID' => $correlationId,
            ]))
            ->timeout((int) config('services.asset.timeout', 10))
            ->post("{$baseUrl}/assets/{$assetId}/rattachement", array_filter([
                'institution_id' => $institutionId,
                'organizational_unit_id' => $organizationalUnitId,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Asset rattachement refused for {$assetId}: HTTP {$response->status()} — ".$response->body()
            );
        }
    }

    private function serviceToken(): string
    {
        return Cache::remember('movement:asset_service_token', 300, function () {
            $response = Http::asForm()->timeout(10)->post(
                (string) config('services.keycloak.token_url'),
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('services.keycloak.client_id'),
                    'client_secret' => config('services.keycloak.client_secret'),
                ],
            );

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Impossible d\'obtenir un jeton service Keycloak.');
            }

            return (string) $response->json('access_token');
        });
    }
}
