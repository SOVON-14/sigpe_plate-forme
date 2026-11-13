<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class KeycloakJwtValidator implements TokenValidator
{
    public function validate(string $token): string
    {
        try {
            $payload = JWT::decode($token, $this->getKeys());
        } catch (Throwable $e) {
            $code = str_contains($e->getMessage(), 'expired') ? 'TOKEN_EXPIRED' : 'TOKEN_INVALID';
            throw new DomainException($code, 'Jeton OIDC invalide ou expiré.');
        }

        $issuer = config('keycloak.issuer');
        if (($payload->iss ?? null) !== $issuer) {
            throw new DomainException('TOKEN_INVALID', 'Émetteur du jeton non reconnu.');
        }

        $audience = config('keycloak.audience');
        $aud = (array) ($payload->aud ?? []);
        if ($audience !== '' && ! in_array($audience, $aud, true) && ($payload->azp ?? null) !== $audience) {
            throw new DomainException('TOKEN_INVALID', 'Audience du jeton non reconnue.');
        }

        if (empty($payload->sub)) {
            throw new DomainException('TOKEN_INVALID', 'Jeton sans sujet.');
        }

        return (string) $payload->sub;
    }

    /** @return array<string, Key> */
    private function getKeys(): array
    {
        $jwks = Cache::remember('keycloak.jwks', (int) config('keycloak.cache_ttl'), function () {
            $response = Http::timeout(10)->get(config('keycloak.jwks_uri'));
            $response->throw();

            return $response->json();
        });

        try {
            return JWK::parseKeySet($jwks);
        } catch (Throwable) {
            throw new DomainException('TOKEN_INVALID', 'Clés JWKS indisponibles.');
        }
    }
}
