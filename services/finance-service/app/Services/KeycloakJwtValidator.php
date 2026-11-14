<?php

namespace App\Services;

use App\Exceptions\DomainException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class KeycloakJwtValidator implements TokenValidator
{
    public function validate(string $token): array
    {
        try {
            $keys = $this->jwks();
            $payload = JWT::decode($token, $keys);
        } catch (ExpiredException $e) {
            throw new DomainException('TOKEN_EXPIRED', 'Jeton expire.', 0, $e);
        } catch (\Throwable $e) {
            throw new DomainException('TOKEN_INVALID', 'Jeton invalide.', 0, $e);
        }

        $claims = (array) $payload;

        $issuer = config('keycloak.issuer');
        if (isset($claims['iss']) && $issuer !== '' && $claims['iss'] !== $issuer) {
            throw new DomainException('TOKEN_INVALID', 'Emetteur du jeton non reconnu.');
        }

        $audience = config('keycloak.audience');
        if ($audience !== '') {
            $aud = $claims['aud'] ?? null;
            $audOk = is_array($aud) ? in_array($audience, $aud, true) : $aud === $audience;
            if (! $audOk && ! in_array($audience, (array) ($claims['aud'] ?? []), true)) {
                throw new DomainException('TOKEN_INVALID', 'Audience du jeton non reconnue.');
            }
        }

        $sub = $claims['sub'] ?? null;
        if (! is_string($sub) || $sub === '') {
            throw new DomainException('TOKEN_INVALID', 'Claim sub manquant.');
        }

        $permissions = $claims['permissions'] ?? [];
        if (! is_array($permissions)) {
            $permissions = [];
        }

        return [
            'sub' => $sub,
            'permissions' => array_values(array_filter($permissions, 'is_string')),
            'institution_id' => isset($claims['institution_id']) && is_string($claims['institution_id'])
                ? $claims['institution_id']
                : null,
            'delegations' => is_array($claims['delegations'] ?? null) ? $claims['delegations'] : [],
        ];
    }

    /**
     * @return array<string, Key>
     */
    private function jwks(): array
    {
        $jwks = Cache::remember('keycloak.jwks', (int) config('keycloak.jwks_cache_ttl'), function () {
            $response = Http::timeout(10)->get((string) config('keycloak.jwks_uri'));
            $response->throw();

            return $response->json();
        });

        return JWK::parseKeySet($jwks);
    }
}
