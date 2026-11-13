<?php

namespace App\Services;

interface TokenValidator
{
    /**
     * Valide un jeton Bearer OIDC et retourne le 'sub' Keycloak.
     *
     * @throws \App\Exceptions\DomainException code TOKEN_INVALID / TOKEN_EXPIRED
     */
    public function validate(string $token): string;
}
