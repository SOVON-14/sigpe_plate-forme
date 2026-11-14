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

        // Fail closed : permission métier portée par le jeton
        // (injectée depuis les habilitations Identity via Keycloak).
        if (! in_array($permission, $claims['permissions'], true)) {
            throw new DomainException('FORBIDDEN', 'Permission métier insuffisante.');
        }

        // Cloisonnement institutionnel (ABAC) : si le jeton porte un
        // institution_id, il ne peut agir que dans ce périmètre.
        // Les opérations couvrent origine ET destination : l'appelant
        // doit être dans l'un des deux perimetres.
        $right = $request->route('right');
        $institutionId = $request->route('operation')?->institution_id
            ?? $request->route('custody')?->institution_id
            ?? ($right !== null ? $right->responsible_institution_id : null)
            ?? $request->input('institution_id');

        if ($claims['institution_id'] !== null && $institutionId !== null) {
            $inScope = in_array($claims['institution_id'], array_filter([
                $institutionId,
                $request->route('operation')?->destination_institution_id,
                $request->input('destination_institution_id'),
                $right?->beneficiary_institution_id,
                $request->input('beneficiary_institution_id'),
            ]), true);

            if (! $inScope) {
                throw new DomainException('FORBIDDEN', 'Hors du périmètre institutionnel autorisé.');
            }
        }

        return $next($request);
    }
}
