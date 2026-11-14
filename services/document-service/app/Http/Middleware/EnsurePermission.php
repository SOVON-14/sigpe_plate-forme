<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $claims = AuthenticateUser::claims($request);

        // Fail closed : permission métier portée par le jeton
        // (injectée depuis les habilitations Identity via Keycloak).
        if (! in_array($permission, $claims['permissions'], true)) {
            throw new DomainException('FORBIDDEN', 'Permission métier insuffisante.');
        }

        // Cloisonnement institutionnel (ABAC) : si le jeton porte un
        // institution_id, il ne peut agir que dans ce périmètre.
        $institutionId = $this->resolveInstitutionId($request);

        if ($claims['institution_id'] !== null && $institutionId !== null
            && $claims['institution_id'] !== $institutionId) {
            throw new DomainException('FORBIDDEN', 'Hors du périmètre institutionnel autorisé.');
        }

        return $next($request);
    }

    private function resolveInstitutionId(Request $request): ?string
    {
        $route = $request->route();
        if ($route === null) {
            return $request->input('institution_id');
        }

        foreach ($route->parameters() as $parameter) {
            if ($parameter instanceof Model && array_key_exists('institution_id', $parameter->getAttributes())) {
                return $parameter->institution_id;
            }
        }

        return $request->input('institution_id');
    }
}
