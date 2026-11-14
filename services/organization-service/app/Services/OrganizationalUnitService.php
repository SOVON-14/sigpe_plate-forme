<?php

namespace App\Services;

use App\Enums\UnitStatus;
use App\Exceptions\DomainException;
use App\Models\Institution;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use Illuminate\Support\Facades\DB;

class OrganizationalUnitService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly OrganizationHistoryService $history,
    ) {}

    public function create(
        Institution $institution,
        OrganizationalUnitType $type,
        string $code,
        string $name,
        ?OrganizationalUnit $parent = null,
        ?string $changedBy = null,
        ?string $correlationId = null,
    ): OrganizationalUnit {
        $this->assertTypeUsable($institution, $type);
        if ($parent !== null) {
            $this->assertParentUsable($institution, $parent);
        }

        return DB::transaction(function () use ($institution, $type, $code, $name, $parent, $changedBy, $correlationId) {
            $unit = OrganizationalUnit::create([
                'institution_id' => $institution->id,
                'parent_id' => $parent?->id,
                'unit_type_id' => $type->id,
                'code' => $code,
                'name' => $name,
                'status' => UnitStatus::Active,
            ]);

            $this->history->record('organizational_unit', $unit->id, 'created', $institution->id, null, [
                'code' => $code, 'name' => $name, 'parent_id' => $parent?->id, 'unit_type_id' => $type->id,
            ], null, $changedBy);

            $this->outbox->record(
                'organization.unit.created',
                'organizational_unit',
                $unit->id,
                ['unit_id' => $unit->id, 'institution_id' => $institution->id, 'parent_id' => $parent?->id,
                 'unit_type_id' => $type->id, 'code' => $code, 'name' => $name, 'status' => 'active'],
                $correlationId,
                institutionId: $institution->id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $unit;
        });
    }

    public function rename(
        OrganizationalUnit $unit,
        string $name,
        ?string $changedBy = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): OrganizationalUnit {
        if ($unit->name === $name) {
            throw new DomainException('UNIT_NAME_UNCHANGED', 'Le nom est déjà celui demandé.');
        }

        return DB::transaction(function () use ($unit, $name, $changedBy, $reason, $correlationId) {
            $old = $unit->name;
            $unit->name = $name;
            $unit->save();

            $this->history->record('organizational_unit', $unit->id, 'renamed', $unit->institution_id,
                ['name' => $old], ['name' => $name], $reason, $changedBy);

            $this->outbox->record(
                'organization.unit.updated',
                'organizational_unit',
                $unit->id,
                ['unit_id' => $unit->id, 'institution_id' => $unit->institution_id, 'change' => 'renamed',
                 'old' => ['name' => $old], 'new' => ['name' => $name]],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $unit->fresh();
        });
    }

    /**
     * Réorganisation : rattachement à une nouvelle unité parente.
     * Aucun nombre fixe de niveaux ; détection de cycle obligatoire.
     */
    public function move(
        OrganizationalUnit $unit,
        ?OrganizationalUnit $newParent,
        ?string $changedBy = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): OrganizationalUnit {
        if ($newParent !== null) {
            if ($newParent->id === $unit->id || $newParent->institution_id !== $unit->institution_id) {
                throw new DomainException('UNIT_MOVE_INVALID', 'Le rattachement cible est invalide.');
            }
            $this->assertParentUsable($unit->institution, $newParent);
            if ($this->isDescendant($newParent, $unit)) {
                throw new DomainException('UNIT_MOVE_CYCLE', 'Le rattachement créerait un cycle hiérarchique.');
            }
        }

        if ($unit->parent_id === ($newParent?->id)) {
            throw new DomainException('UNIT_MOVE_UNCHANGED', 'L\'unité a déjà ce rattachement.');
        }

        return DB::transaction(function () use ($unit, $newParent, $changedBy, $reason, $correlationId) {
            $oldParentId = $unit->parent_id;
            $unit->parent_id = $newParent?->id;
            $unit->save();

            $this->history->record('organizational_unit', $unit->id, 'moved', $unit->institution_id,
                ['parent_id' => $oldParentId], ['parent_id' => $newParent?->id], $reason, $changedBy);

            $this->outbox->record(
                'organization.unit.updated',
                'organizational_unit',
                $unit->id,
                ['unit_id' => $unit->id, 'institution_id' => $unit->institution_id, 'change' => 'moved',
                 'old' => ['parent_id' => $oldParentId], 'new' => ['parent_id' => $newParent?->id]],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $unit->fresh();
        });
    }

    public function changeStatus(
        OrganizationalUnit $unit,
        UnitStatus $status,
        ?string $changedBy = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): OrganizationalUnit {
        if ($unit->status === $status) {
            throw new DomainException('UNIT_STATUS_UNCHANGED', 'Le statut est déjà celui demandé.');
        }

        return DB::transaction(function () use ($unit, $status, $changedBy, $reason, $correlationId) {
            $old = $unit->status->value;
            $unit->status = $status;
            $unit->save();

            $this->history->record('organizational_unit', $unit->id, 'status_changed', $unit->institution_id,
                ['status' => $old], ['status' => $status->value], $reason, $changedBy);

            $this->outbox->record(
                'organization.unit.status_changed',
                'organizational_unit',
                $unit->id,
                ['unit_id' => $unit->id, 'institution_id' => $unit->institution_id,
                 'status' => $status->value, 'previous_status' => $old, 'reason' => $reason],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $unit->fresh();
        });
    }

    private function isDescendant(OrganizationalUnit $candidateAncestor, OrganizationalUnit $unit): bool
    {
        $current = $candidateAncestor->parent;
        $guard = 0;
        while ($current !== null && $guard++ < 100) {
            if ($current->id === $unit->id) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }

    private function assertTypeUsable(Institution $institution, OrganizationalUnitType $type): void
    {
        if ($type->institution_id !== null && $type->institution_id !== $institution->id) {
            throw new DomainException('UNIT_TYPE_FOREIGN', 'Ce type d\'unité appartient à une autre institution.');
        }
    }

    private function assertParentUsable(Institution $institution, OrganizationalUnit $parent): void
    {
        if ($parent->institution_id !== $institution->id) {
            throw new DomainException('UNIT_PARENT_FOREIGN', 'Le rattachement appartient à une autre institution.');
        }
        if ($parent->status !== UnitStatus::Active) {
            throw new DomainException('UNIT_PARENT_INACTIVE', 'Le rattachement cible n\'est pas actif.');
        }
    }
}
