<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client HTTP vers Asset Service (API Gateway).
 * Authentification service-to-service : client credentials Keycloak
 * (compte de service Acquisition avec la permission asset.manage).
 * Asset reste le SEUL createur d'asset_id et du code patrimonial.
 */
class AssetGateway
{
    /**
     * Enregistre un bien patrimonial pour un article accepte.
     *
     * @param  array<string, mixed>  $payload
     * @return string asset_id retourne par Asset
     */
    public function registerAsset(array $payload, string $idempotencyKey, ?string $correlationId = null): string
    {
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
            ->post("{$baseUrl}/assets", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Asset registration refused: HTTP '.$response->status().' — '.$response->body()
            );
        }

        $assetId = $response->json('data.id') ?? $response->json('id');
        if (! is_string($assetId)) {
            throw new RuntimeException('Reponse Asset sans asset_id exploitable.');
        }

        return $assetId;
    }

    private function serviceToken(): string
    {
        return Cache::remember('acquisition:asset_service_token', 300, function () {
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
