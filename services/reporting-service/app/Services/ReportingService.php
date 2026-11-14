<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\AssetProjection;
use App\Models\IngestedEvent;
use App\Models\ReportRun;
use App\Models\ReportSequence;
use App\Models\ReportTemplate;
use App\Models\SavedView;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reporting Service (CDC §4.17) — projections en lecture seule.
 *
 * - consomme les evenements metier (binding '#') : stockage brut +
 *   projection patrimoniale par bien ;
 * - indicateurs et tableaux de bord national/ministeriel ;
 * - vues enregistrees par profil (jamais d'elargissement des droits) ;
 * - modeles nationaux de rapports, executions figees, exports CSV/JSON ;
 * - Reporting ne modifie jamais les donnees sources.
 */
class ReportingService
{
    /** Mapping etat projete par type d'evenement. */
    private const STATE_MAP = [
        'movement.loan.activated' => 'on_loan',
        'movement.loan.returned' => 'active',
        'movement.interministerial_transfer.completed' => 'active',
        'movement.internal_transfer.completed' => 'active',
        'maintenance.intervention.started' => 'under_maintenance',
        'maintenance.intervention.completed' => 'active',
        'finance.disposal_executed' => 'disposed',
        'finance.disposal_approved' => 'under_investigation',
        'inventory.anomaly.detected' => 'under_investigation',
        'movement.incident_reported' => 'under_investigation',
    ];

    private const DEFAULT_TEMPLATES = [
        [
            'code' => 'parc-actifs',
            'name' => 'Etat du parc d\'actifs',
            'description' => 'Inventaire projete des biens par etat et categorie sur la periode.',
            'category' => 'patrimoine',
            'confidentiality' => 'internal',
            'parameters' => [['name' => 'state', 'type' => 'string', 'required' => false]],
        ],
        [
            'code' => 'mouvements-periode',
            'name' => 'Mouvements de la periode',
            'description' => 'Evenements de mouvement (affectations, transferts, prets) sur la periode.',
            'category' => 'mouvements',
            'confidentiality' => 'internal',
            'parameters' => [],
        ],
        [
            'code' => 'anomalies-inventaire',
            'name' => 'Anomalies d\'inventaire',
            'description' => 'Constats et anomalies detectes lors des campagnes d\'inventaire.',
            'category' => 'inventaire',
            'confidentiality' => 'restricted',
            'parameters' => [],
        ],
        [
            'code' => 'valeur-patrimoine',
            'name' => 'Synthese de la valeur du patrimoine',
            'description' => 'Couverture de la valorisation et couts cumules connues sur la periode.',
            'category' => 'finance',
            'confidentiality' => 'confidential',
            'parameters' => [],
        ],
    ];

    public function __construct(private readonly OutboxRecorder $outbox) {}

    // ─── Ingestion ─────────────────────────────────────────────────────

    /**
     * Ingere un evenement : stockage brut + projection patrimoniale.
     * Retourne 'ingested', 'duplicate' ou 'ignored'.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function ingest(array $envelope): string
    {
        $eventId = $envelope['event_id'] ?? null;
        if ($eventId === null) {
            return 'ignored';
        }

        $exists = IngestedEvent::where('event_id', $eventId)->exists();
        if ($exists) {
            return 'duplicate';
        }

        $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
        $eventType = (string) ($envelope['event_type'] ?? 'unknown');
        $assetId = $this->extractAssetId($envelope, $data);

        DB::transaction(function () use ($envelope, $eventId, $eventType, $data, $assetId): void {
            IngestedEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'producer' => $envelope['producer'] ?? null,
                'aggregate_type' => $envelope['aggregate_type'] ?? null,
                'aggregate_id' => $envelope['aggregate_id'] ?? null,
                'asset_id' => $assetId,
                'institution_id' => $envelope['institution_id'] ?? ($data['institution_id'] ?? null),
                'correlation_id' => $envelope['correlation_id'] ?? null,
                'occurred_at' => $envelope['occurred_at'] ?? now(),
                'payload' => $data,
                'ingested_at' => now(),
            ]);

            $this->updateProjection($eventType, $assetId, $data, $envelope);
        });

        return 'ingested';
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  array<string, mixed>  $data
     */
    private function extractAssetId(array $envelope, array $data): ?string
    {
        $candidate = $data['asset_id'] ?? null;
        if (is_string($candidate) && Str::isUuid($candidate)) {
            return $candidate;
        }

        if (($envelope['aggregate_type'] ?? null) === 'asset' && isset($envelope['aggregate_id'])
            && Str::isUuid((string) $envelope['aggregate_id'])) {
            return (string) $envelope['aggregate_id'];
        }

        return null;
    }

    /**
     * Met a jour la projection patrimoniale du bien si l'evenement
     * le concerne. Ne modifie jamais les donnees sources — uniquement
     * la projection locale.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $envelope
     */
    private function updateProjection(string $eventType, ?string $assetId, array $data, array $envelope): void
    {
        if ($assetId === null) {
            return;
        }

        $occurredAt = isset($envelope['occurred_at']) ? Carbon::parse($envelope['occurred_at']) : now();

        // Normalisation : les cles metier ignorent le suffixe de version.
        $baseType = (string) preg_replace('/\.v\d+$/', '', $eventType);

        if ($baseType === 'asset.registered') {
            AssetProjection::updateOrCreate(
                ['asset_id' => $assetId],
                [
                    'patrimonial_code' => $data['patrimonial_code'] ?? null,
                    'designation' => $data['designation'] ?? null,
                    'category_id' => $data['category_id'] ?? null,
                    'institution_id' => $envelope['institution_id'] ?? ($data['institution_id'] ?? null),
                    'state' => 'active',
                    'entry_mode' => $data['entry_mode'] ?? null,
                    'value_known' => ! (bool) ($data['value_unknown'] ?? true),
                    'last_event_type' => $eventType,
                    'last_event_at' => $occurredAt,
                ],
            );

            return;
        }

        $state = self::STATE_MAP[$baseType] ?? null;
        if ($state === null && ! str_starts_with($eventType, 'asset.')) {
            return; // evenement sans incidence sur l'etat projete
        }

        $projection = AssetProjection::firstOrCreate(
            ['asset_id' => $assetId],
            ['state' => 'unknown'],
        );

        $updates = [
            'last_event_type' => $eventType,
            'last_event_at' => $occurredAt,
        ];
        if ($state !== null) {
            $updates['state'] = $state;
        }
        // Un transfert interministerial confirme peut changer l'institution.
        if ($baseType === 'movement.interministerial_transfer.completed'
            && isset($data['destination_institution_id'])) {
            $updates['institution_id'] = $data['destination_institution_id'];
        }
        if ($baseType === 'finance.disposal_executed') {
            $updates['value_known'] = $projection->value_known;
        }

        $projection->update($updates);
    }

    // ─── Tableau de bord ───────────────────────────────────────────────

    /**
     * Metriques du tableau de bord. institutionId null = national
     * (habilitations reporting.dashboard.national requises en amont).
     *
     * @return array<string, mixed>
     */
    public function dashboard(?string $institutionId): array
    {
        $projectionQuery = AssetProjection::query()
            ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId));

        $byState = (clone $projectionQuery)->selectRaw('state, COUNT(*) as n')
            ->groupBy('state')->pluck('n', 'state')->map(fn ($v) => (int) $v)->all();

        $eventsQuery = IngestedEvent::query()
            ->when($institutionId !== null, fn ($q) => $q->where('institution_id', $institutionId));

        $countPrefix = fn (string $prefix) => (clone $eventsQuery)
            ->where('event_type', 'like', $prefix.'.%')->count();

        $lastEvent = (clone $eventsQuery)->max('occurred_at');
        $syncLag = $lastEvent !== null
            ? max(0, (int) now()->diffInSeconds(Carbon::parse($lastEvent), false) * -1)
            : null;

        return [
            'scope' => $institutionId === null ? 'national' : 'institutional',
            'institution_id' => $institutionId,
            'assets_total' => (clone $projectionQuery)->count(),
            'assets_by_state' => $byState,
            'movements_count' => $countPrefix('movement'),
            'maintenance_interventions' => (clone $eventsQuery)
                ->where('event_type', 'like', 'maintenance.intervention.%')->count(),
            'inventory_campaigns' => (clone $eventsQuery)
                ->where('event_type', 'like', 'inventory.campaign.%')->count(),
            'anomalies_open' => (clone $eventsQuery)
                ->where('event_type', 'like', 'inventory.%anomal%')->orWhere('event_type', 'like', '%.anomaly.%')->count(),
            'acquisitions_count' => $countPrefix('acquisition'),
            'disposals_count' => (clone $eventsQuery)
                ->where('event_type', 'like', 'finance.disposal_%')->count(),
            'events_ingested' => (clone $eventsQuery)->count(),
            'last_event_at' => $lastEvent,
            'sync_lag_seconds' => $syncLag,
            'computed_at' => now()->toIso8601String(),
        ];
    }

    // ─── Recherche ─────────────────────────────────────────────────────

    /**
     * Recherche multicritere sur les projections — le perimetre
     * institutionnel est toujours applique en amont.
     *
     * @param  array<string, mixed>  $filters
     */
    public function searchAssets(array $filters, ?string $institutionScope): LengthAwarePaginator
    {
        $query = AssetProjection::query()
            ->when($institutionScope !== null, fn ($q) => $q->where('institution_id', $institutionScope));

        if (! empty($filters['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('patrimonial_code', 'like', $term)
                ->orWhere('designation', 'like', $term));
        }
        foreach (['institution_id', 'category_id', 'state', 'entry_mode'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderBy('patrimonial_code')->paginate(
            min((int) ($filters['per_page'] ?? 25), 100), ['*'], 'page', (int) ($filters['page'] ?? 1)
        );
    }

    /**
     * Fiche projete + chronologie patrimoniale complete.
     *
     * @return array{projection: AssetProjection, chronology: Collection}|null
     */
    public function assetDetail(string $assetId, ?string $institutionScope): ?array
    {
        $projection = AssetProjection::where('asset_id', $assetId)->first();
        if ($projection === null) {
            return null;
        }
        if ($institutionScope !== null && $projection->institution_id !== null
            && $projection->institution_id !== $institutionScope) {
            throw new DomainException('FORBIDDEN', 'Bien hors du perimetre institutionnel.');
        }

        $chronology = IngestedEvent::where('asset_id', $assetId)
            ->orderBy('occurred_at')
            ->get(['event_type', 'occurred_at', 'producer', 'correlation_id']);

        return ['projection' => $projection, 'chronology' => $chronology];
    }

    // ─── Indicateurs ───────────────────────────────────────────────────

    /**
     * @return list<array{code: string, label: string, value: mixed, unit: string}>
     */
    public function indicators(?string $institutionId): array
    {
        $dashboard = $this->dashboard($institutionId);

        return [
            ['code' => 'assets.total', 'label' => 'Biens au registre (projection)', 'value' => $dashboard['assets_total'], 'unit' => 'unite'],
            ['code' => 'assets.active', 'label' => 'Biens actifs', 'value' => $dashboard['assets_by_state']['active'] ?? 0, 'unit' => 'unite'],
            ['code' => 'assets.disposed', 'label' => 'Biens sortis', 'value' => $dashboard['assets_by_state']['disposed'] ?? 0, 'unit' => 'unite'],
            ['code' => 'movements.total', 'label' => 'Mouvements enregistres', 'value' => $dashboard['movements_count'], 'unit' => 'evenement'],
            ['code' => 'maintenance.interventions', 'label' => 'Interventions de maintenance', 'value' => $dashboard['maintenance_interventions'], 'unit' => 'evenement'],
            ['code' => 'inventory.campaigns', 'label' => 'Campagnes d\'inventaire', 'value' => $dashboard['inventory_campaigns'], 'unit' => 'campagne'],
            ['code' => 'anomalies.open', 'label' => 'Anomalies detectees', 'value' => $dashboard['anomalies_open'], 'unit' => 'evenement'],
            ['code' => 'events.ingested', 'label' => 'Evenements ingeres', 'value' => $dashboard['events_ingested'], 'unit' => 'evenement'],
        ];
    }

    // ─── Vues enregistrees ─────────────────────────────────────────────

    /**
     * @return list<SavedView>
     */
    public function viewsFor(string $accountId): array
    {
        return SavedView::where('account_id', $accountId)->orderBy('name')->get()->all();
    }

    /**
     * @param  array{name: string, profile?: string, filters: array<string, mixed>}  $data
     */
    public function createView(string $accountId, array $data, ?string $correlationId = null): SavedView
    {
        return SavedView::create([
            'account_id' => $accountId,
            'name' => $data['name'],
            'profile' => $data['profile'] ?? 'default',
            'filters' => $data['filters'],
        ]);
    }

    public function deleteView(SavedView $view, string $accountId): void
    {
        if ($view->account_id !== $accountId) {
            throw new DomainException('FORBIDDEN', 'Vue d\'un autre compte.');
        }
        $view->delete();
    }

    // ─── Modeles et executions de rapports ────────────────────────────

    public function ensureDefaultTemplates(): void
    {
        foreach (self::DEFAULT_TEMPLATES as $tpl) {
            ReportTemplate::firstOrCreate(['code' => $tpl['code']], $tpl);
        }
    }

    /**
     * @return list<ReportTemplate>
     */
    public function templates(): array
    {
        $this->ensureDefaultTemplates();

        return ReportTemplate::where('is_active', true)->orderBy('code')->get()->all();
    }

    /**
     * Genere un rapport : perimetre, periode, criteres, confidentialite
     * et reference figes dans report_runs.
     *
     * @param  array{period_start: string, period_end: string, institution_id?: string|null, parameters?: array<string, mixed>}  $data
     */
    public function runReport(string $templateCode, array $data, ?string $institutionScope, string $actorId, ?string $correlationId = null): ReportRun
    {
        $this->ensureDefaultTemplates();
        $template = ReportTemplate::where('code', $templateCode)->where('is_active', true)->first();
        if ($template === null) {
            throw new DomainException('TEMPLATE_NOT_FOUND', 'Modele de rapport inconnu.');
        }

        // Perimetre : le jeton borne toujours ; institution_id demande
        // doit etre dans le perimetre (national = jeton sans institution).
        $requestedInstitution = $data['institution_id'] ?? null;
        if ($institutionScope !== null && $requestedInstitution !== null
            && $requestedInstitution !== $institutionScope) {
            throw new DomainException('FORBIDDEN', 'Perimetre de rapport hors habilitations.');
        }
        if ($institutionScope === null && $requestedInstitution === null && $template->confidentiality === 'confidential') {
            // national autorise pour les modeles confidentiels
        }
        $scope = $institutionScope ?? $requestedInstitution;

        $run = DB::transaction(function () use ($template, $data, $scope, $actorId): ReportRun {
            $run = ReportRun::create([
                'reference' => $this->nextReference(),
                'template_code' => $template->code,
                'institution_id' => $scope,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'parameters' => $data['parameters'] ?? [],
                'status' => 'pending',
                'confidentiality' => $template->confidentiality,
                'generated_by' => $actorId,
            ]);

            try {
                $result = $this->buildResult($template->code, $run, $scope, $data);
                $run->update([
                    'status' => 'completed',
                    'generated_at' => now(),
                    'result' => $result,
                ]);
            } catch (\Throwable $e) {
                $run->update(['status' => 'failed', 'error' => $e->getMessage()]);
                throw $e;
            }

            return $run;
        });

        $rowCount = count($run->result['rows'] ?? []);
        $this->outbox->record(
            'reporting.report_generated',
            'report_run',
            $run->id,
            [
                'report_run_id' => $run->id,
                'reference' => $run->reference,
                'template_code' => $run->template_code,
                'template_name' => $template->name,
                'institution_id' => $scope,
                'period_start' => $run->period_start->toDateString(),
                'period_end' => $run->period_end->toDateString(),
                'generated_by' => $actorId,
                'generated_at' => now()->toIso8601String(),
                'row_count' => $rowCount,
                'confidentiality' => $run->confidentiality,
            ],
            correlationId: $correlationId,
            institutionId: $scope,
            actor: ['type' => 'user', 'id' => $actorId],
        );

        return $run->fresh();
    }

    /**
     * Construit le dataset fige du rapport selon le modele.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildResult(string $templateCode, ReportRun $run, ?string $scope, array $data): array
    {
        $start = Carbon::parse($data['period_start'])->startOfDay();
        $end = Carbon::parse($data['period_end'])->endOfDay();
        $parameters = $data['parameters'] ?? [];

        $rows = match ($templateCode) {
            'parc-actifs' => $this->parcActifsRows($scope, $parameters),
            'mouvements-periode' => $this->mouvementsRows($scope, $start, $end),
            'anomalies-inventaire' => $this->anomaliesRows($scope, $start, $end),
            'valeur-patrimoine' => $this->valeurPatrimoineRows($scope),
            default => [],
        };

        return [
            'title' => $run->template_code,
            'scope' => $scope === null ? 'national' : 'institutional',
            'institution_id' => $scope,
            'period' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'parameters' => $parameters,
            'generated_at' => now()->toIso8601String(),
            'row_count' => count($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<array<string, mixed>>
     */
    private function parcActifsRows(?string $scope, array $parameters): array
    {
        return AssetProjection::query()
            ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope))
            ->when(! empty($parameters['state']), fn ($q) => $q->where('state', $parameters['state']))
            ->orderBy('patrimonial_code')
            ->limit(5000)
            ->get()
            ->map(fn (AssetProjection $p) => [
                'patrimonial_code' => $p->patrimonial_code,
                'designation' => $p->designation,
                'category_id' => $p->category_id,
                'institution_id' => $p->institution_id,
                'state' => $p->state,
                'value_known' => $p->value_known,
                'last_event_at' => $p->last_event_at?->toIso8601String(),
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mouvementsRows(?string $scope, Carbon $start, Carbon $end): array
    {
        return IngestedEvent::query()
            ->where('event_type', 'like', 'movement.%')
            ->whereBetween('occurred_at', [$start, $end])
            ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope))
            ->orderBy('occurred_at')
            ->limit(10000)
            ->get(['event_type', 'occurred_at', 'producer', 'aggregate_id', 'asset_id', 'institution_id'])
            ->map(fn (IngestedEvent $e) => [
                'event_type' => $e->event_type,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'producer' => $e->producer,
                'aggregate_id' => $e->aggregate_id,
                'asset_id' => $e->asset_id,
                'institution_id' => $e->institution_id,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anomaliesRows(?string $scope, Carbon $start, Carbon $end): array
    {
        return IngestedEvent::query()
            ->where(fn ($q) => $q->where('event_type', 'like', 'inventory.%anomal%')
                ->orWhere('event_type', 'like', '%.anomaly.%')
                ->orWhere('event_type', 'like', 'inventory.finding.%'))
            ->whereBetween('occurred_at', [$start, $end])
            ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope))
            ->orderBy('occurred_at')
            ->limit(10000)
            ->get(['event_type', 'occurred_at', 'producer', 'aggregate_id', 'asset_id', 'institution_id', 'payload'])
            ->map(fn (IngestedEvent $e) => [
                'event_type' => $e->event_type,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
                'producer' => $e->producer,
                'aggregate_id' => $e->aggregate_id,
                'asset_id' => $e->asset_id,
                'institution_id' => $e->institution_id,
                'finding_type' => $e->payload['finding_type'] ?? $e->payload['anomaly_type'] ?? null,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valeurPatrimoineRows(?string $scope): array
    {
        $query = AssetProjection::query()
            ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope));

        return [
            [
                'metric' => 'assets_total',
                'value' => $query->count(),
            ],
            [
                'metric' => 'assets_value_known',
                'value' => (clone $query)->where('value_known', true)->count(),
            ],
            [
                'metric' => 'assets_value_unknown',
                'value' => (clone $query)->where('value_known', false)->count(),
            ],
            [
                'metric' => 'valuation_events',
                'value' => IngestedEvent::where('event_type', 'like', 'finance.valuation_%')
                    ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope))
                    ->count(),
            ],
            [
                'metric' => 'cost_events',
                'value' => IngestedEvent::where('event_type', 'like', 'finance.cost_%')
                    ->when($scope !== null, fn ($q) => $q->where('institution_id', $scope))
                    ->count(),
            ],
        ];
    }

    private function nextReference(): string
    {
        $sequence = ReportSequence::lockForUpdate()->firstOrCreate(['id' => 1]);
        $sequence->increment('last_number');
        $sequence->refresh();

        return 'RPT-'.now()->format('Y').'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
    }
}
