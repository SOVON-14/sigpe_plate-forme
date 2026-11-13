<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\FunctionalAccount;
use App\Services\TokenValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFunctionalAccount
{
    public const ACCOUNT_ATTRIBUTE = 'sigpe.functional_account';

    public function __construct(private readonly TokenValidator $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new DomainException('UNAUTHENTICATED', 'Jeton Bearer OIDC requis.');
        }

        $sub = $this->tokens->validate(substr($header, 7));

        $account = FunctionalAccount::where('keycloak_sub', $sub)->first();
        if ($account === null || ! $account->isActive()) {
            throw new DomainException('FORBIDDEN', 'Compte fonctionnel inexistant ou inactif.');
        }

        $request->attributes->set(self::ACCOUNT_ATTRIBUTE, $account);
        $request->setUserResolver(fn () => $account);

        return $next($request);
    }
}
