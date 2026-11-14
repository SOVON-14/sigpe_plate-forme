<?php

namespace App\Services;

use App\Enums\AdministrativeStatus;
use App\Enums\EntryMode;
use App\Enums\PhysicalState;
use App\Exceptions\DomainException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetSubcategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetService
{
    public function __construct(
        private readonly OutboxRecorder $outbox,
        private readonly AssetHistoryService $history,
        private readonly PatrimonialCodeGenerator $codes,
    ) {}

    /**
     * Enregistrement d'un bien au registre maître — Asset est le SEUL
     * service autorisé à créer asset_id et le code patrimonial
     * (catalogue §3.2). Acquisition transmet les biens acceptés ici.
     */
    public function register(
        string $institutionId,
        ?string $organizationalUnitId,
        AssetCategory $category,
        ?AssetSubcategory $subcategory,
        string $designation,
        ?string $description,
        string $entryDate,
        EntryMode $entryMode,
        ?string $entryReference,
        PhysicalState $physicalState,
        ?string $location,
        ?array $technicalAttributes,
        bool $valueUnknown,
        ?string $valueUnknownJustification,
        ?string $registeredBy,
        ?string $correlationId = null,
    ): Asset {
        $this->assertSubcategoryUsable($institutionId, $category, $subcategory);
        if ($valueUnknown && ($valueUnknownJustification === null || trim($valueUnknownJustification) === '')) {
            throw new DomainException(
                'VALUE_UNKNOWN_REQUIRES_JUSTIFICATION',
                'Une valeur inconnue exige une justification (CDC §4.4).',
            );
        }

        return DB::transaction(function () use (
            $institutionId, $organizationalUnitId, $category, $subcategory,
            $designation, $description, $entryDate, $entryMode, $entryReference,
            $physicalState, $location, $technicalAttributes, $valueUnknown,
            $valueUnknownJustification, $registeredBy, $correlationId,
        ) {
            $asset = Asset::create([
                'institution_id' => $institutionId,
                'organizational_unit_id' => $organizationalUnitId,
                'category_id' => $category->id,
                'subcategory_id' => $subcategory?->id,
                'patrimonial_code' => $this->codes->next(),
                'designation' => $designation,
                'description' => $description,
                'entry_date' => $entryDate,
                'entry_mode' => $entryMode,
                'entry_reference' => $entryReference,
                'physical_state' => $physicalState,
                'administrative_status' => AdministrativeStatus::Actif,
                'location' => $location,
                'qr_token' => Str::random(48),
                'technical_attributes' => $technicalAttributes,
                'value_unknown' => $valueUnknown,
                'value_unknown_justification' => $valueUnknownJustification,
                'registered_by' => $registeredBy,
            ]);

            $this->history->record($asset->id, 'registered', $institutionId, null, [
                'patrimonial_code' => $asset->patrimonial_code,
                'designation' => $designation,
                'category_id' => $category->id,
                'entry_mode' => $entryMode->value,
            ], null, $registeredBy);

            $this->outbox->record(
                'asset.registered',
                'asset',
                $asset->id,
                [
                    'asset_id' => $asset->id,
                    'institution_id' => $institutionId,
                    'patrimonial_code' => $asset->patrimonial_code,
                    'designation' => $designation,
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory?->id,
                    'physical_state' => $physicalState->value,
                    'entry_mode' => $entryMode->value,
                    'value_unknown' => $valueUnknown,
                ],
                $correlationId,
                institutionId: $institutionId,
                actor: ['type' => 'user', 'id' => $registeredBy],
            );

            return $asset;
        });
    }

    /**
     * Mise à jour des champs descriptifs / localisation / attributs
     * techniques. asset_id et code patrimonial sont immuables.
     */
    public function updateDetails(
        Asset $asset,
        array $changes,
        ?string $changedBy,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Asset {
        $allowed = ['designation', 'description', 'location', 'technical_attributes', 'organizational_unit_id'];
        $changes = array_intersect_key($changes, array_flip($allowed));
        if ($changes === []) {
            throw new DomainException('ASSET_NO_CHANGE', 'Aucune modification fournie.');
        }

        return DB::transaction(function () use ($asset, $changes, $changedBy, $reason, $correlationId) {
            $old = [];
            foreach ($changes as $field => $value) {
                $old[$field] = $asset->{$field};
                $asset->{$field} = $value;
            }
            $asset->save();

            $this->history->record($asset->id, 'updated', $asset->institution_id, $old, $changes, $reason, $changedBy);

            $this->outbox->record(
                'asset.updated',
                'asset',
                $asset->id,
                ['asset_id' => $asset->id, 'institution_id' => $asset->institution_id, 'change' => 'details',
                 'old' => $old, 'new' => $changes],
                $correlationId,
                institutionId: $asset->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $asset->fresh();
        });
    }

    public function changePhysicalState(
        Asset $asset,
        PhysicalState $state,
        ?string $changedBy,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Asset {
        if ($asset->physical_state === $state) {
            throw new DomainException('ASSET_STATE_UNCHANGED', 'L\'état physique est déjà celui demandé.');
        }

        return DB::transaction(function () use ($asset, $state, $changedBy, $reason, $correlationId) {
            $old = $asset->physical_state->value;
            $asset->physical_state = $state;
            $asset->save();

            $this->history->record($asset->id, 'physical_state_changed', $asset->institution_id,
                ['physical_state' => $old], ['physical_state' => $state->value], $reason, $changedBy);

            $this->outbox->record(
                'asset.physical_state_changed',
                'asset',
                $asset->id,
                ['asset_id' => $asset->id, 'institution_id' => $asset->institution_id,
                 'physical_state' => $state->value, 'previous_state' => $old, 'reason' => $reason],
                $correlationId,
                institutionId: $asset->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $asset->fresh();
        });
    }

    /**
     * Changement de statut administratif (actif, provisoire, en_reforme, sorti).
     * La sortie définitive relève d'un dossier de réforme validé par Workflow.
     */
    public function changeAdministrativeStatus(
        Asset $asset,
        AdministrativeStatus $status,
        ?string $changedBy,
        ?string $reason = null,
        ?string $correlationId = null,
    ): Asset {
        if ($asset->administrative_status === $status) {
            throw new DomainException('ASSET_STATUS_UNCHANGED', 'Le statut est déjà celui demandé.');
        }
        if ($status === AdministrativeStatus::Sorti && $asset->administrative_status !== AdministrativeStatus::EnReforme) {
            throw new DomainException(
                'ASSET_EXIT_REQUIRES_REFORM',
                'La sortie définitive exige un statut en_reforme préalable (dossier de réforme).',
            );
        }

        return DB::transaction(function () use ($asset, $status, $changedBy, $reason, $correlationId) {
            $old = $asset->administrative_status->value;
            $asset->administrative_status = $status;
            $asset->save();

            $this->history->record($asset->id, 'status_changed', $asset->institution_id,
                ['administrative_status' => $old], ['administrative_status' => $status->value], $reason, $changedBy);

            $this->outbox->record(
                'asset.status_changed',
                'asset',
                $asset->id,
                ['asset_id' => $asset->id, 'institution_id' => $asset->institution_id,
                 'status' => $status->value, 'previous_status' => $old, 'reason' => $reason],
                $correlationId,
                institutionId: $asset->institution_id,
                actor: ['type' => 'user', 'id' => $changedBy],
            );

            return $asset->fresh();
        });
    }

    /**
     * Confirmation de rattachement inter-ministériel — RÈGLE CRITIQUE
     * (catalogue §6, TEC-017) : Asset confirme le changement
     * d'institution et attribue le NOUVEL identifiant ministériel ;
     * asset_id reste inchangé ; l'ancien code est conservé dans
     * l'historique. Movement ne clôture le transfert qu'après cet
     * événement asset.rattachement_confirmed.
     */
    public function confirmRattachement(
        Asset $asset,
        string $newInstitutionId,
        ?string $newOrganizationalUnitId,
        ?string $confirmedBy,
        ?string $correlationId = null,
    ): Asset {
        if ($asset->institution_id === $newInstitutionId) {
            throw new DomainException('ASSET_ALREADY_IN_INSTITUTION', 'Le bien appartient déjà à cette institution.');
        }
        if ($asset->administrative_status !== AdministrativeStatus::Actif) {
            throw new DomainException(
                'ASSET_NOT_TRANSFERABLE',
                'Seul un bien au statut actif peut changer de rattachement.',
            );
        }

        return DB::transaction(function () use ($asset, $newInstitutionId, $newOrganizationalUnitId, $confirmedBy, $correlationId) {
            $oldInstitutionId = $asset->institution_id;
            $oldCode = $asset->patrimonial_code;
            $newCode = $this->codes->next();

            $asset->institution_id = $newInstitutionId;
            $asset->organizational_unit_id = $newOrganizationalUnitId;
            $asset->patrimonial_code = $newCode;
            $asset->save();

            $this->history->record($asset->id, 'rattachement_confirmed', $newInstitutionId,
                ['institution_id' => $oldInstitutionId, 'patrimonial_code' => $oldCode, 'organizational_unit_id' => $asset->getOriginal('organizational_unit_id')],
                ['institution_id' => $newInstitutionId, 'patrimonial_code' => $newCode, 'organizational_unit_id' => $newOrganizationalUnitId],
                null, $confirmedBy);

            $this->outbox->record(
                'asset.rattachement_confirmed',
                'asset',
                $asset->id,
                [
                    'asset_id' => $asset->id,
                    'institution_id' => $newInstitutionId,
                    'previous_institution_id' => $oldInstitutionId,
                    'patrimonial_code' => $newCode,
                    'previous_patrimonial_code' => $oldCode,
                ],
                $correlationId,
                institutionId: $newInstitutionId,
                actor: ['type' => 'service', 'id' => 'movement-service'],
            );

            return $asset->fresh();
        });
    }

    private function assertSubcategoryUsable(
        string $institutionId,
        AssetCategory $category,
        ?AssetSubcategory $subcategory,
    ): void {
        if ($subcategory === null) {
            return;
        }
        if ($subcategory->category_id !== $category->id) {
            throw new DomainException('ASSET_SUBCATEGORY_MISMATCH', 'La sous-catégorie n\'appartient pas à la catégorie.');
        }
        if ($subcategory->institution_id !== null && $subcategory->institution_id !== $institutionId) {
            throw new DomainException('ASSET_SUBCATEGORY_FOREIGN', 'Cette sous-catégorie appartient à une autre institution.');
        }
    }
}
