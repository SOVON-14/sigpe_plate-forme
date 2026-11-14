<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

class PositionService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly OrganizationHistoryService $history,
    ) {}

    public function create(
        OrganizationalUnit $unit,
        string $code,
        string $title,
        ?string $changedBy = null,
        ?string $correlationId = null,
    ): Position {
        return DB::transaction(function () use ($unit, $code, $title, $changedBy, $correlationId) {
            $position = Position::create([
                'organizational_unit_id' => $unit->id,
                'code' => $code,
                'title' => $title,
                'status' => 'active',
            ]);

            $this->history->record('position', $position->id, 'created', $unit->institution_id, null, [
                'organizational_unit_id' => $unit->id, 'code' => $code, 'title' => $title,
            ], null, $changedBy);

            $this->outbox->record(
                'organization.position.created',
                'position',
                $position->id,
                ['position_id' => $position->id, 'unit_id' => $unit->id, 'institution_id' => $unit->institution_id,
                 'code' => $code, 'title' => $title, 'status' => 'active'],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $position;
        });
    }

    /**
     * Affectation d'un responsable (compte fonctionnel Identity) à un poste.
     * Le compte est référencé par UUID — aucune FK inter-services (ADR-003).
     */
    public function assignResponsible(
        Position $position,
        string $functionalAccountId,
        ?string $changedBy = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Position {
        if ($position->functional_account_id === $functionalAccountId) {
            throw new DomainException('POSITION_HOLDER_UNCHANGED', 'Ce compte occupe déjà ce poste.');
        }

        return DB::transaction(function () use ($position, $functionalAccountId, $changedBy, $reason, $correlationId) {
            $oldAccountId = $position->functional_account_id;
            $position->functional_account_id = $functionalAccountId;
            $position->assigned_at = now();
            $position->status = 'active';
            $position->save();

            // Si le poste pourvu est le chef d'unité, mettre à jour le pointeur.
            $unit = $position->unit;
            if ($unit->head_position_id === $position->id && $unit->head_position_id !== null) {
                // rien à faire : head_position_id pointe déjà sur ce poste
            }

            $this->history->record('position', $position->id, 'responsible_changed', $unit->institution_id,
                ['functional_account_id' => $oldAccountId],
                ['functional_account_id' => $functionalAccountId], $reason, $changedBy);

            $this->outbox->record(
                'organization.position.responsible_changed',
                'position',
                $position->id,
                ['position_id' => $position->id, 'unit_id' => $unit->id, 'institution_id' => $unit->institution_id,
                 'functional_account_id' => $functionalAccountId, 'previous_account_id' => $oldAccountId],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $position->fresh();
        });
    }

    public function revokeResponsible(
        Position $position,
        ?string $changedBy = null,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Position {
        if ($position->functional_account_id === null) {
            throw new DomainException('POSITION_NOT_FILLED', 'Ce poste n\'a pas de responsable.');
        }

        return DB::transaction(function () use ($position, $changedBy, $reason, $correlationId) {
            $oldAccountId = $position->functional_account_id;
            $unit = $position->unit;

            $position->functional_account_id = null;
            $position->assigned_at = null;
            $position->save();

            $this->history->record('position', $position->id, 'responsible_changed', $unit->institution_id,
                ['functional_account_id' => $oldAccountId], ['functional_account_id' => null], $reason, $changedBy);

            $this->outbox->record(
                'organization.position.responsible_changed',
                'position',
                $position->id,
                ['position_id' => $position->id, 'unit_id' => $unit->id, 'institution_id' => $unit->institution_id,
                 'functional_account_id' => null, 'previous_account_id' => $oldAccountId],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $position->fresh();
        });
    }

    /**
     * Désigne le poste de direction d'une unité (head_position_id).
     * Le poste doit appartenir à l'unité et être actif.
     */
    public function setUnitHead(
        OrganizationalUnit $unit,
        Position $position,
        ?string $changedBy = null,
        ?string $correlationId = null,
    ): OrganizationalUnit {
        if ($position->organizational_unit_id !== $unit->id) {
            throw new DomainException('POSITION_FOREIGN_UNIT', 'Le poste n\'appartient pas à cette unité.');
        }
        if ($position->status !== 'active') {
            throw new DomainException('POSITION_INACTIVE', 'Le poste n\'est pas actif.');
        }

        return DB::transaction(function () use ($unit, $position, $changedBy, $correlationId) {
            $old = $unit->head_position_id;
            $unit->head_position_id = $position->id;
            $unit->save();

            $this->history->record('organizational_unit', $unit->id, 'responsible_changed', $unit->institution_id,
                ['head_position_id' => $old], ['head_position_id' => $position->id], null, $changedBy);

            $this->outbox->record(
                'organization.unit.updated',
                'organizational_unit',
                $unit->id,
                ['unit_id' => $unit->id, 'institution_id' => $unit->institution_id, 'change' => 'head_changed',
                 'old' => ['head_position_id' => $old], 'new' => ['head_position_id' => $position->id]],
                $correlationId,
                institutionId: $unit->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $unit->fresh();
        });
    }
}
