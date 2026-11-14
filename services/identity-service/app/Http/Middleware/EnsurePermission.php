<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\FunctionalAccount;
use App\Services\EffectiveAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function __construct(private readonly EffectiveAccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        /** @var FunctionalAccount|null $account */
        $account = $request->attributes->get(AuthenticateFunctionalAccount::ACCOUNT_ATTRIBUTE);

        if ($account === null) {
            throw new DomainException('UNAUTHENTICATED', 'Authentification requise.');
        }

        $institutionId = $request->query('institution_id') ?? $request->input('institution_id');
        $unitId = $request->query('organizational_unit_id') ?? $request->input('organizational_unit_id');

        if (! $this->access->can($account, $permission, $institutionId, $unitId)) {
            throw new DomainException('FORBIDDEN', 'Permission métier insuffisante.');
        }

        return $next($request);
    }
}
