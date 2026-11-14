<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'institution_id' => $this->institution_id,
            'destination_institution_id' => $this->destination_institution_id,
            'origin_unit_id' => $this->origin_unit_id,
            'destination_unit_id' => $this->destination_unit_id,
            'asset_ids' => $this->whenLoaded('assets', fn () => $this->assetIds()),
            'workflow_instance_id' => $this->workflow_instance_id,
            'operation_ref' => $this->operation_ref,
            'motif' => $this->motif,
            'loan_start_at' => $this->loan_start_at?->toIso8601String(),
            'loan_end_at' => $this->loan_end_at?->toIso8601String(),
            'custodian_account_id' => $this->custodian_account_id,
            'convention_ref' => $this->convention_ref,
            'conditions' => $this->conditions,
            'departure_confirmed_by' => $this->departure_confirmed_by,
            'departure_confirmed_at' => $this->departure_confirmed_at?->toIso8601String(),
            'reception_confirmed_by' => $this->reception_confirmed_by,
            'reception_confirmed_at' => $this->reception_confirmed_at?->toIso8601String(),
            'asset_confirmed_at' => $this->asset_confirmed_at?->toIso8601String(),
            'asset_confirmations' => $this->asset_confirmations,
            'incident_type' => $this->incident_type instanceof \BackedEnum ? $this->incident_type->value : $this->incident_type,
            'incident_description' => $this->incident_description,
            'returned_at' => $this->returned_at?->toIso8601String(),
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_comment' => $this->decision_comment,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'assets' => $this->whenLoaded('assets', fn () => $this->assets->map(fn ($a) => [
                'asset_id' => $a->asset_id,
                'patrimonial_code' => $a->patrimonial_code,
            ])->all()),
            'custodies' => CustodyResource::collection($this->whenLoaded('custodies')),
            'steps' => $this->when($this->exists, fn () => $this->stepsProgress()),
        ];
    }

    /**
     * Etapes de progression pour le detail (cle/label/status).
     *
     * @return array<int, array{key: string, label: string, status: string, completed_at: ?string, confirmed_by: ?string}>
     */
    private function stepsProgress(): array
    {
        $steps = [
            ['key' => 'created', 'label' => 'Creation', 'status' => 'completed', 'completed_at' => $this->created_at?->toIso8601String(), 'confirmed_by' => $this->created_by],
            ['key' => 'validation', 'label' => 'Validation', 'status' => 'pending', 'completed_at' => null, 'confirmed_by' => null],
            ['key' => 'departure', 'label' => 'Confirmation de sortie', 'status' => 'pending', 'completed_at' => null, 'confirmed_by' => null],
            ['key' => 'reception', 'label' => 'Confirmation de reception', 'status' => 'pending', 'completed_at' => null, 'confirmed_by' => null],
            ['key' => 'asset_confirmation', 'label' => 'Confirmation Asset (TEC-017)', 'status' => 'pending', 'completed_at' => null, 'confirmed_by' => null],
        ];

        $isTransfer = in_array($this->type->value, ['internal_transfer', 'interministerial_transfer'], true);
        $needsValidation = $this->workflow_instance_id !== null || $this->type->value !== 'internal_transfer';

        foreach ($steps as &$step) {
            $step['status'] = match ($step['key']) {
                'created' => 'completed',
                'validation' => $needsValidation
                    ? ($this->status->value === 'rejected' ? 'rejected'
                        : ($this->status->value === 'pending_validation' ? 'in_progress'
                            : (in_array($this->status->value, ['pending_departure', 'pending_reception', 'pending_asset_confirmation', 'completed', 'returned', 'active'], true) ? 'completed' : 'pending')))
                    : 'not_applicable',
                'departure' => ! $isTransfer ? 'not_applicable'
                    : ($this->departure_confirmed_at !== null ? 'completed'
                        : (in_array($this->status->value, ['pending_departure', 'pending_reception', 'pending_asset_confirmation', 'completed'], true) ? 'in_progress' : 'pending')),
                'reception' => ! $isTransfer ? 'not_applicable'
                    : ($this->reception_confirmed_at !== null ? 'completed'
                        : ($this->status->value === 'pending_reception' ? 'in_progress' : 'pending')),
                'asset_confirmation' => ! $this->isInterministerial() ? 'not_applicable'
                    : ($this->asset_confirmed_at !== null ? 'completed'
                        : ($this->status->value === 'pending_asset_confirmation' ? 'in_progress' : 'pending')),
                default => 'pending',
            };
            if ($step['key'] === 'departure' && $this->departure_confirmed_at !== null) {
                $step['completed_at'] = $this->departure_confirmed_at->toIso8601String();
                $step['confirmed_by'] = $this->departure_confirmed_by;
            }
            if ($step['key'] === 'reception' && $this->reception_confirmed_at !== null) {
                $step['completed_at'] = $this->reception_confirmed_at->toIso8601String();
                $step['confirmed_by'] = $this->reception_confirmed_by;
            }
            if ($step['key'] === 'asset_confirmation' && $this->asset_confirmed_at !== null) {
                $step['completed_at'] = $this->asset_confirmed_at->toIso8601String();
            }
        }

        return $steps;
    }
}
