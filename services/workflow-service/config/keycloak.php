<?php

return [
    'issuer' => env('KEYCLOAK_ISSUER', 'http://keycloak:8080/realms/sigpe'),
    'jwks_uri' => env('KEYCLOAK_JWKS_URI', 'http://keycloak:8080/realms/sigpe/protocol/openid-connect/certs'),
    'audience' => env('KEYCLOAK_AUDIENCE', 'sigpe-api'),
    'cache_ttl' => (int) env('KEYCLOAK_JWKS_CACHE_TTL', 3600),
];
