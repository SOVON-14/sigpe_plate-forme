<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $claims = AuthenticateUser::claims($request);

        // Fail closed : le jeton doit porter la permission métier,
        // injectée par Keycloak depuis les habilitations Identity.
        if (! in_array($permission, $claims['permissions'], true)) {
            throw new DomainException('FORBIDDEN', 'Permission métier insuffisante.');
        }

        return $next($request);
    }
}
