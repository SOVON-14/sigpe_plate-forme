<?php

namespace App\Services;

use App\Enums\InstitutionStatus;
use App\Exceptions\DomainException;
use App\Models\Institution;
use Illuminate\Support\Facades\DB;

class InstitutionService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly OrganizationHistoryService $history,
    ) {}

    public function create(
        string $code,
        string $name,
        string $type,
        ?string $description,
        ?string $createdBy,
        ?string $correlationId = null,
    ): Institution {
        return DB::transaction(function () use ($code, $name, $type, $description, $createdBy, $correlationId) {
            $institution = Institution::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'status' => InstitutionStatus::Active,
                'description' => $description,
                'activated_at' => now(),
                'created_by' => $createdBy,
            ]);

            $this->history->record('institution', $institution->id, 'created', $institution->id, null, [
                'code' => $code, 'name' => $name, 'type' => $type,
            ], null, $createdBy);

            $this->outbox->record(
                'organization.institution.created',
                'institution',
                $institution->id,
                ['institution_id' => $institution->id, 'code' => $code, 'name' => $name, 'type' => $type, 'status' => 'active'],
                $correlationId,
                institutionId: $institution->id,
                actor: ['type' => 'user', 'id' => $createdBy],
            );

            return $institution;
        });
    }

    public function changeStatus(
        Institution $institution,
        InstitutionStatus $status,
        ?string $changedBy,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Institution {
        if ($institution->status === $status) {
            throw new DomainException('INSTITUTION_STATUS_UNCHANGED', 'Le statut est déjà celui demandé.');
        }

        return DB::transaction(function () use ($institution, $status, $changedBy, $reason, $correlationId) {
            $old = $institution->status->value;
            $institution->status = $status;
            $institution->suspended_at = $status === InstitutionStatus::Suspended ? now() : $institution->suspended_at;
            $institution->save();

            $this->history->record('institution', $institution->id, 'status_changed', $institution->id,
                ['status' => $old], ['status' => $status->value], $reason, $changedBy);

            $this->outbox->record(
                'organization.institution.status_changed',
                'institution',
                $institution->id,
                ['institution_id' => $institution->id, 'status' => $status->value, 'previous_status' => $old, 'reason' => $reason],
                $correlationId,
                institutionId: $institution->id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $institution->fresh();
        });
    }
}
