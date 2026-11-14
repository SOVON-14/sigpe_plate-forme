<?php

namespace App\Services;

interface TokenValidator
{
    /**
     * Valide un jeton Bearer OIDC et retourne ses claims utiles :
     * sub (string), permissions (string[]), institution_id (?string).
     *
     * @return array{sub: string, permissions: string[], institution_id: ?string}
     *
     * @throws \App\Exceptions\DomainException code TOKEN_INVALID / TOKEN_EXPIRED
     */
    public function validate(string $token): array;
}
