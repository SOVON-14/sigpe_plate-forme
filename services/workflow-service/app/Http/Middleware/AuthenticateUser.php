<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Services\TokenValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateUser
{
    public const CLAIMS_ATTRIBUTE = 'sigpe.claims';

    public function __construct(private readonly TokenValidator $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new DomainException('UNAUTHENTICATED', 'Jeton Bearer OIDC requis.');
        }

        $claims = $this->tokens->validate(substr($header, 7));
        $request->attributes->set(self::CLAIMS_ATTRIBUTE, $claims);

        return $next($request);
    }

    /**
     * @return array{sub: string, permissions: string[], institution_id: ?string}
     */
    public static function claims(Request $request): array
    {
        /** @var array|null $claims */
        $claims = $request->attributes->get(self::CLAIMS_ATTRIBUTE);
        if ($claims === null) {
            throw new DomainException('UNAUTHENTICATED', 'Authentification requise.');
        }

        return $claims;
    }
}
