<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Intervention;
use App\Models\InterventionPlan;
use App\Models\MaintenanceRequest;
use App\Models\SparePart;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
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

        foreach (['maintenanceRequest', 'plan', 'intervention', 'supplier', 'part', 'warehouse', 'movement'] as $key) {
            $model = $route?->parameter($key);
            if ($model instanceof MaintenanceRequest
                || $model instanceof InterventionPlan
                || $model instanceof Intervention
                || $model instanceof SparePart
                || $model instanceof StockMovement
                || $model instanceof Warehouse
                || $model instanceof Supplier) {
                // Les prestataires nationaux (institution_id null)
                // restent visibles par tous ; les relations
                // ministerielles sont cloisonnees par leur
                // institution_id.
                return $model->institution_id;
            }
        }

        return $request->input('institution_id');
    }
}
