<?php

namespace App\Services;

use App\Enums\CostCategory;
use App\Enums\DepreciationMethod;
use App\Enums\DisposalStatus;
use App\Enums\DisposalType;
use App\Enums\SourceType;
use App\Enums\ValuationType;
use App\Exceptions\DomainException;
use App\Models\AssetCost;
use App\Models\AssetValuation;
use App\Models\DepreciationEntry;
use App\Models\Disposal;
use App\Models\DisposalHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moteur financier SIGPE (CDC §4.11).
 *
 * - valorisations par type (acquisition, actuelle, nette comptable,
 *   remplacement) — valeur connue ou indeterminee, historisees ;
 * - amortissements selon methode et parametres autorises ;
 * - couts associes aux biens (evenements maintenance/acquisition +
 *   saisie manuelle + repartition biens partages) ;
 * - sorties definitives par procedure : competence requise selon la
 *   valeur, decision de l'autorite competente via Workflow, execution
 *   et cloture ; perte/vol = investigation, le bien reste au registre.
 */
class FinanceService
{
    /** Correspondance couts d'intervention Maintenance -> categories Finance. */
    private const INTERVENTION_COST_MAP = [
        'labor' => CostCategory::Repair,
        'parts' => CostCategory::MaintenanceCost,
        'supplies' => CostCategory::MaintenanceCost,
        'transport' => CostCategory::Transport,
        'external_services' => CostCategory::MaintenanceCost,
        'other' => CostCategory::Other,
    ];

    public function __construct(private readonly OutboxRecorder $outbox) {}

    // ─── Valorisations ─────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    public function createValuation(array $data, ?string $institutionId, string $actorId, ?string $correlationId = null): AssetValuation
    {
        $amount = $data['amount'] ?? null;
        $isDeterminate = $amount !== null;

        $valuation = AssetValuation::create([
            'asset_id' => $data['asset_id'],
            'institution_id' => $institutionId,
            'valuation_type' => $data['valuation_type'],
            'valuation_date' => $data['valuation_date'],
            'amount' => $amount,
            'currency' => strtoupper($data['currency'] ?? 'XOF'),
            'is_determinate' => $isDeterminate,
            'depreciation_method' => $data['depreciation_method'] ?? null,
            'depreciation_rate_annual' => $data['depreciation_rate_annual'] ?? null,
            'useful_life_months' => $data['useful_life_months'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $actorId,
        ]);

        $this->outbox->record(
            'finance.valuation_recorded',
            'asset_valuation',
            $valuation->id,
            [
                'valuation_id' => $valuation->id,
                'asset_id' => $valuation->asset_id,
                'institution_id' => $institutionId,
                'valuation_type' => $valuation->valuation_type->value,
                'valuation_date' => $valuation->valuation_date->toDateString(),
                'amount' => $valuation->amount !== null ? (float) $valuation->amount : null,
                'currency' => $valuation->currency,
                'is_determinate' => $isDeterminate,
                'depreciation_method' => $valuation->depreciation_method,
                'recorded_by' => $actorId,
                'recorded_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $institutionId,
            actor: ['type' => 'user', 'id' => $actorId],
        );

        return $valuation;
    }

    /**
     * Vue valorisation courante : derniere valorisation par type,
     * amortissement cumule, total des couts, VNC calculee.
     *
     * @return array<string, mixed>
     */
    public function valuationView(string $assetId, ?string $institutionId = null): array
    {
        $valuations = AssetValuation::where('asset_id', $assetId)
            ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId))
            ->orderByDesc('valuation_date')
            ->orderByDesc('created_at')
            ->get();

        $latest = [];
        foreach (ValuationType::cases() as $type) {
            $latest[$type->value] = $valuations->firstWhere('valuation_type', $type)?->only([
                'id', 'amount', 'currency', 'valuation_date', 'is_determinate',
            ]);
        }

        $base = $latest['acquisition']['amount'] ?? $latest['current']['amount'] ?? null;
        $accumulated = (float) DepreciationEntry::where('asset_id', $assetId)->sum('amount');
        $netBook = $base !== null ? max(0.0, (float) $base - $accumulated) : null;

        $costs = AssetCost::where('asset_id', $assetId)
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $isFullyDeterminate = collect($latest)->every(
            fn ($v) => $v === null || $v['is_determinate']
        );

        return [
            'asset_id' => $assetId,
            'acquisition_value' => $latest['acquisition']['amount'] ?? null,
            'current_value' => $latest['current']['amount'] ?? null,
            'net_book_value' => $netBook,
            'replacement_value' => $latest['replacement']['amount'] ?? null,
            'accumulated_depreciation' => $accumulated,
            'total_costs' => $costs->map(fn ($v) => (float) $v)->all(),
            'is_fully_determinate' => (bool) $isFullyDeterminate && $base !== null,
            'latest_valuation_at' => $valuations->first()?->created_at?->toIso8601String(),
        ];
    }

    // ─── Amortissements ────────────────────────────────────────────────

    /**
     * Enregistre une dotation. Si `amount` absent, calcule selon la
     * derniere valorisation determinante porteuse d'une methode.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordDepreciation(string $assetId, array $data, ?string $institutionId, string $actorId, ?string $correlationId = null): DepreciationEntry
    {
        $existing = DepreciationEntry::where('asset_id', $assetId)
            ->whereDate('period_start', $data['period_start'])
            ->whereDate('period_end', $data['period_end'])
            ->first();
        if ($existing !== null) {
            throw new DomainException('DEPRECIATION_PERIOD_EXISTS', 'Une dotation existe deja pour cette periode.');
        }

        $amount = $data['amount'] ?? null;
        $method = $data['method'] ?? null;

        if ($amount === null) {
            [$amount, $method] = $this->computeDepreciation($assetId, $data['period_start'], $data['period_end'], $method);
        }

        $entry = DepreciationEntry::create([
            'asset_id' => $assetId,
            'institution_id' => $institutionId,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'amount' => $amount,
            'method' => $method,
            'recorded_by' => $actorId,
        ]);

        $view = $this->valuationView($assetId, $institutionId);

        $this->outbox->record(
            'finance.depreciation_recorded',
            'depreciation_entry',
            $entry->id,
            [
                'entry_id' => $entry->id,
                'asset_id' => $assetId,
                'institution_id' => $institutionId,
                'period_start' => $entry->period_start->toDateString(),
                'period_end' => $entry->period_end->toDateString(),
                'amount' => (float) $entry->amount,
                'method' => $entry->method,
                'accumulated_depreciation' => $view['accumulated_depreciation'],
                'net_book_value' => $view['net_book_value'],
                'recorded_by' => $actorId,
                'recorded_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $institutionId,
            actor: ['type' => 'user', 'id' => $actorId],
        );

        return $entry;
    }

    /**
     * Calcule la dotation : lineaire = base/duree par mois ;
     * declining = taux annuel applique a la VNC courante.
     *
     * @return array{0: float, 1: string}
     */
    private function computeDepreciation(string $assetId, string $periodStart, string $periodEnd, ?string $method): array
    {
        $valuation = AssetValuation::where('asset_id', $assetId)
            ->whereNotNull('depreciation_method')
            ->whereNotNull('amount')
            ->where('is_determinate', true)
            ->orderByDesc('valuation_date')
            ->first();

        if ($valuation === null) {
            throw new DomainException('VALUATION_NOT_DETERMINATE', 'Aucune valorisation determinante avec methode d\'amortissement.');
        }

        $methodEnum = DepreciationMethod::tryFrom($method ?? $valuation->depreciation_method)
            ?? DepreciationMethod::tryFrom((string) $valuation->depreciation_method);
        if ($methodEnum === null || $methodEnum === DepreciationMethod::None) {
            throw new DomainException('DEPRECIATION_METHOD_INVALID', 'Methode d\'amortissement invalide ou absente.');
        }

        $months = max(1, (int) abs((strtotime($periodEnd) - strtotime($periodStart)) / (60 * 60 * 24 * 30.44)) + 1);
        $base = (float) $valuation->amount;

        $amount = match ($methodEnum) {
            DepreciationMethod::Linear => $valuation->useful_life_months
                ? round($base / $valuation->useful_life_months * $months, 2)
                : throw new DomainException('USEFUL_LIFE_MISSING', 'Duree de vie utile requise pour l\'amortissement lineaire.'),
            DepreciationMethod::DecliningBalance => $valuation->depreciation_rate_annual !== null
                ? round($base * ((float) $valuation->depreciation_rate_annual / 100) / 12 * $months, 2)
                : throw new DomainException('RATE_MISSING', 'Taux annuel requis pour l\'amortissement degressif.'),
            DepreciationMethod::None => 0.0,
        };

        return [$amount, $methodEnum->value];
    }

    // ─── Couts ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCost(array $data, ?string $institutionId, string $actorId, ?string $correlationId = null): AssetCost
    {
        $cost = AssetCost::create([
            'asset_id' => $data['asset_id'],
            'institution_id' => $institutionId,
            'category' => $data['category'],
            'source_type' => $data['source_type'] ?? SourceType::Manual->value,
            'source_id' => $data['source_id'] ?? null,
            'amount' => $data['amount'],
            'currency' => strtoupper($data['currency'] ?? 'XOF'),
            'incurred_at' => $data['incurred_at'],
            'beneficiary_account_id' => $data['beneficiary_account_id'] ?? null,
            'description' => $data['description'],
            'recorded_by' => $actorId,
        ]);

        $this->emitCostRecorded($cost, $institutionId, $correlationId, ['type' => 'user', 'id' => $actorId]);

        return $cost;
    }

    /**
     * Consommation de maintenance.intervention.completed : une ligne
     * de cout par categorie non nulle, idempotente par (source, categorie).
     *
     * @param  array<string, mixed>  $data
     */
    public function recordInterventionCosts(array $data, ?string $institutionId, ?string $correlationId = null): int
    {
        $interventionId = $data['intervention_id'] ?? null;
        $assetId = $data['asset_id'] ?? null;
        if ($interventionId === null || $assetId === null) {
            return 0;
        }

        $costs = $data['costs'] ?? [];
        $created = 0;
        $actor = ['type' => 'service', 'id' => (string) Str::uuid7()];

        foreach (self::INTERVENTION_COST_MAP as $key => $category) {
            $amount = (float) ($costs[$key] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $exists = AssetCost::where('source_type', SourceType::Maintenance->value)
                ->where('source_id', $interventionId)
                ->where('category', $category->value)
                ->exists();
            if ($exists) {
                continue;
            }

            $cost = AssetCost::create([
                'asset_id' => $assetId,
                'institution_id' => $institutionId ?? ($data['institution_id'] ?? null),
                'category' => $category,
                'source_type' => SourceType::Maintenance->value,
                'source_id' => $interventionId,
                'amount' => $amount,
                'currency' => 'XOF',
                'incurred_at' => $data['completed_at'] ?? now()->toDateString(),
                'description' => 'Cout intervention maintenance '.$interventionId.' ('.$key.')',
                'recorded_by' => (string) Str::uuid7(),
            ]);

            $this->emitCostRecorded($cost, $cost->institution_id, $correlationId, $actor);
            $created++;
        }

        return $created;
    }

    /**
     * Synthese des couts d'un bien.
     *
     * @return array<string, mixed>
     */
    public function costSummary(string $assetId, ?string $institutionId = null): array
    {
        $rows = AssetCost::where('asset_id', $assetId)
            ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId))
            ->get();
        $byCategory = $rows->groupBy(fn ($c) => $c->category->value)
            ->map(fn ($g) => (float) $g->sum('amount'));
        $currency = $rows->first()?->currency ?? 'XOF';

        return [
            'asset_id' => $assetId,
            'total_amount' => (float) $rows->where('currency', $currency)->sum('amount'),
            'currency' => $currency,
            'by_category' => $byCategory->all(),
            'cost_count' => $rows->count(),
        ];
    }

    // ─── Sorties definitives ───────────────────────────────────────────

    /**
     * Cree un dossier de sortie. Perte/vol => investigation immediate
     * (le bien reste au registre, aucune sortie automatique).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDisposal(array $data, ?string $institutionId, string $actorId, ?string $correlationId = null): Disposal
    {
        $type = DisposalType::from($data['disposal_type']);
        $view = $this->valuationView($data['asset_id'], $institutionId);
        $netBook = $view['net_book_value'];
        $isDeterminate = $netBook !== null;
        $competence = $this->requiredCompetence($netBook);

        $isIncident = in_array($type, [DisposalType::Loss, DisposalType::Theft], true);
        if ($isIncident && empty($data['incident_reported_at'])) {
            throw new DomainException('INCIDENT_DATE_REQUIRED', 'Date de declaration requise pour une perte ou un vol.');
        }

        $open = Disposal::where('asset_id', $data['asset_id'])
            ->whereNotIn('status', [DisposalStatus::Closed->value, DisposalStatus::Rejected->value, DisposalStatus::Cancelled->value])
            ->exists();
        if ($open) {
            throw new DomainException('DISPOSAL_ALREADY_OPEN', 'Un dossier de sortie est deja ouvert pour ce bien.');
        }

        $disposal = DB::transaction(function () use ($data, $institutionId, $actorId, $type, $netBook, $isDeterminate, $competence, $isIncident): Disposal {
            $disposal = Disposal::create([
                'asset_id' => $data['asset_id'],
                'institution_id' => $institutionId,
                'disposal_type' => $type,
                'reason' => $data['reason'],
                'status' => $isIncident ? DisposalStatus::UnderInvestigation : DisposalStatus::Draft,
                'estimated_value' => $data['estimated_value'] ?? null,
                'currency' => strtoupper($data['currency'] ?? 'XOF'),
                'net_book_value' => $netBook,
                'is_value_determinate' => $isDeterminate,
                'required_competence_level' => $competence,
                'created_by' => $actorId,
            ]);

            $this->recordHistory($disposal, null, $disposal->status->value, $actorId, null);

            return $disposal;
        });

        return $disposal;
    }

    /**
     * Soumet au circuit Workflow.
     */
    public function submit(Disposal $disposal, ?string $workflowInstanceId, string $actorId): Disposal
    {
        if (! in_array($disposal->status, [DisposalStatus::Draft, DisposalStatus::UnderInvestigation], true)) {
            throw new DomainException('DISPOSAL_NOT_SUBMITTABLE', 'Le dossier ne peut pas etre soumis dans cet etat.');
        }

        $from = $disposal->status->value;
        $disposal->update([
            'status' => DisposalStatus::PendingValidation,
            'workflow_instance_id' => $workflowInstanceId ?? $disposal->workflow_instance_id,
        ]);
        $this->recordHistory($disposal, $from, DisposalStatus::PendingValidation->value, $actorId, 'Soumission au circuit de validation');

        return $disposal->fresh();
    }

    /**
     * Consommation de workflow.instance_completed.
     */
    public function handleWorkflowOutcome(string $workflowInstanceId, string $outcome, string $actorId): void
    {
        $disposal = Disposal::where('workflow_instance_id', $workflowInstanceId)->first();
        if ($disposal === null) {
            return;
        }
        if ($disposal->status !== DisposalStatus::PendingValidation) {
            return;
        }

        match ($outcome) {
            'approved' => $this->approve($disposal, $actorId),
            'rejected' => $this->transition($disposal, DisposalStatus::Rejected, $actorId, 'Refus du circuit de validation'),
            'cancelled' => $this->transition($disposal, DisposalStatus::Cancelled, $actorId, 'Circuit annule'),
            'correction_requested' => $this->transition($disposal, DisposalStatus::Draft, $actorId, 'Correction demandee'),
            default => null,
        };
    }

    /**
     * Approbation par l'autorite competente (issue du circuit).
     */
    private function approve(Disposal $disposal, string $actorId): void
    {
        $disposal->update([
            'status' => DisposalStatus::Approved,
            'decided_by' => $actorId,
            'decided_at' => now(),
        ]);
        $this->recordHistory($disposal, DisposalStatus::PendingValidation->value, DisposalStatus::Approved->value, $actorId, 'Decision competente : approbation');

        $this->outbox->record(
            'finance.disposal_approved',
            'disposal',
            $disposal->id,
            [
                'disposal_id' => $disposal->id,
                'asset_id' => $disposal->asset_id,
                'institution_id' => $disposal->institution_id,
                'disposal_type' => $disposal->disposal_type->value,
                'net_book_value' => $disposal->net_book_value !== null ? (float) $disposal->net_book_value : null,
                'is_value_determinate' => (bool) $disposal->is_value_determinate,
                'required_competence_level' => $disposal->required_competence_level->value,
                'decided_by' => $actorId,
                'decided_at' => now()->toIso8601String(),
            ],
            institutionId: $disposal->institution_id,
            actor: ['type' => 'service', 'id' => (string) Str::uuid7()],
        );
    }

    /**
     * Execution de la sortie — uniquement apres approbation.
     */
    public function execute(Disposal $disposal, string $actorId, ?string $correlationId = null): Disposal
    {
        if ($disposal->status !== DisposalStatus::Approved) {
            throw new DomainException('DISPOSAL_NOT_EXECUTABLE', 'La sortie exige une approbation prealable.');
        }

        $disposal->update([
            'status' => DisposalStatus::Executed,
            'executed_by' => $actorId,
            'executed_at' => now(),
        ]);
        $this->recordHistory($disposal, DisposalStatus::Approved->value, DisposalStatus::Executed->value, $actorId, 'Execution de la sortie definitive');

        $this->outbox->record(
            'finance.disposal_executed',
            'disposal',
            $disposal->id,
            [
                'disposal_id' => $disposal->id,
                'asset_id' => $disposal->asset_id,
                'institution_id' => $disposal->institution_id,
                'disposal_type' => $disposal->disposal_type->value,
                'net_book_value' => $disposal->net_book_value !== null ? (float) $disposal->net_book_value : null,
                'executed_by' => $actorId,
                'executed_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $disposal->institution_id,
            actor: ['type' => 'user', 'id' => $actorId],
        );

        return $disposal->fresh();
    }

    /**
     * Cloture du dossier — la preuve est conservee par Document Service.
     */
    public function close(Disposal $disposal, string $actorId, ?string $correlationId = null): Disposal
    {
        if ($disposal->status !== DisposalStatus::Executed) {
            throw new DomainException('DISPOSAL_NOT_CLOSABLE', 'Seul un dossier execute peut etre cloture.');
        }

        $disposal->update([
            'status' => DisposalStatus::Closed,
            'closed_by' => $actorId,
            'closed_at' => now(),
        ]);
        $this->recordHistory($disposal, DisposalStatus::Executed->value, DisposalStatus::Closed->value, $actorId, 'Cloture du dossier de sortie');

        $this->outbox->record(
            'finance.disposal_closed',
            'disposal',
            $disposal->id,
            [
                'disposal_id' => $disposal->id,
                'asset_id' => $disposal->asset_id,
                'institution_id' => $disposal->institution_id,
                'disposal_type' => $disposal->disposal_type->value,
                'final_value' => $disposal->net_book_value !== null ? (float) $disposal->net_book_value : null,
                'closed_by' => $actorId,
                'closed_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $disposal->institution_id,
            actor: ['type' => 'user', 'id' => $actorId],
        );

        return $disposal->fresh();
    }

    /**
     * Resolution d'une investigation perte/vol.
     * asset_found => cloture sans sortie (retour au registre) ;
     * loss/theft confirme => retour en validation pour decision.
     *
     * @param  array{resolution: string, comment?: string|null}  $data
     */
    public function resolveInvestigation(Disposal $disposal, array $data, string $actorId): Disposal
    {
        if ($disposal->status !== DisposalStatus::UnderInvestigation) {
            throw new DomainException('DISPOSAL_NOT_INVESTIGATING', 'Le dossier n\'est pas en investigation.');
        }

        return match ($data['resolution']) {
            'asset_found' => $this->transition($disposal, DisposalStatus::Closed, $actorId, 'Bien retrouve — retour au registre sans sortie'),
            'loss_confirmed', 'theft_confirmed' => $this->transition($disposal, DisposalStatus::PendingValidation, $actorId, 'Perte/vol confirme — decision de sortie requise'),
            default => throw new DomainException('RESOLUTION_INVALID', 'Resolution invalide.'),
        };
    }

    /**
     * Annulation d'un dossier non execute.
     */
    public function cancel(Disposal $disposal, ?string $reason, string $actorId): Disposal
    {
        if (in_array($disposal->status, [
            DisposalStatus::Executed, DisposalStatus::Closed, DisposalStatus::Cancelled, DisposalStatus::Rejected,
        ], true)) {
            throw new DomainException('DISPOSAL_NOT_CANCELLABLE', 'Le dossier ne peut plus etre annule.');
        }

        return $this->transition($disposal, DisposalStatus::Cancelled, $actorId, $reason ?? 'Annulation du dossier');
    }

    /**
     * Niveau de competence requis selon la valeur nette comptable.
     * Seuils configurables (parametres nationaux a confirmer).
     */
    public function requiredCompetence(?float $netBookValue): string
    {
        $thresholds = config('finance.competence_thresholds', [
            'ministerial' => 50_000_000,
            'national' => 500_000_000,
        ]);

        if ($netBookValue === null) {
            return 'ministerial'; // valeur indeterminee : prudence
        }
        if ($netBookValue >= ($thresholds['national'] ?? 500_000_000)) {
            return 'national';
        }
        if ($netBookValue >= ($thresholds['ministerial'] ?? 50_000_000)) {
            return 'ministerial';
        }

        return 'institutional';
    }

    // ─── Consommateurs d'evenements ────────────────────────────────────

    /**
     * asset.registered : Finance enregistre la valeur — connue ou
     * indeterminee (l'evenement Asset ne porte pas de montant).
     *
     * @param  array<string, mixed>  $data
     */
    public function handleAssetRegistered(array $data, ?string $institutionId, ?string $correlationId = null): void
    {
        $assetId = $data['asset_id'] ?? null;
        if ($assetId === null) {
            return;
        }

        $exists = AssetValuation::where('asset_id', $assetId)
            ->where('valuation_type', ValuationType::Acquisition->value)
            ->exists();
        if ($exists) {
            return;
        }

        $this->createValuation([
            'asset_id' => $assetId,
            'valuation_type' => ValuationType::Acquisition->value,
            'valuation_date' => now()->toDateString(),
            'amount' => null,
            'notes' => 'Valorisation initiale — montant a completer (evenement asset.registered).',
        ], $institutionId ?? ($data['institution_id'] ?? null), (string) Str::uuid7(), $correlationId);
    }

    // ─── Internes ──────────────────────────────────────────────────────

    private function transition(Disposal $disposal, DisposalStatus $to, string $actorId, ?string $comment): Disposal
    {
        $from = $disposal->status->value;
        $disposal->update(['status' => $to]);
        $this->recordHistory($disposal, $from, $to->value, $actorId, $comment);

        return $disposal->fresh();
    }

    private function recordHistory(Disposal $disposal, ?string $from, string $to, string $actorId, ?string $comment): void
    {
        DisposalHistory::create([
            'disposal_id' => $disposal->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actorId,
            'comment' => $comment,
        ]);
    }

    /**
     * @param  array{type?: string, id?: string}  $actor
     */
    private function emitCostRecorded(AssetCost $cost, ?string $institutionId, ?string $correlationId, array $actor): void
    {
        $this->outbox->record(
            'finance.cost_recorded',
            'asset_cost',
            $cost->id,
            [
                'cost_id' => $cost->id,
                'asset_id' => $cost->asset_id,
                'institution_id' => $institutionId,
                'category' => $cost->category->value,
                'source_type' => $cost->source_type->value,
                'source_id' => $cost->source_id,
                'amount' => (float) $cost->amount,
                'currency' => $cost->currency,
                'incurred_at' => $cost->incurred_at->toDateString(),
                'beneficiary_account_id' => $cost->beneficiary_account_id,
                'description' => $cost->description,
                'recorded_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $institutionId,
            actor: $actor,
        );
    }
}
