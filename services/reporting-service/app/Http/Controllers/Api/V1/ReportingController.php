<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\CreateView;
use App\Http\Requests\RunReport;
use App\Http\Resources\AssetProjectionResource;
use App\Http\Resources\ReportRunDetailResource;
use App\Http\Resources\ReportRunResource;
use App\Http\Resources\ReportTemplateResource;
use App\Http\Resources\SavedViewResource;
use App\Models\ReportRun;
use App\Models\SavedView;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportingController extends Controller
{
    public function __construct(private readonly ReportingService $reporting) {}

    // ─── Dashboard ─────────────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $claims = AuthenticateUser::claims($request);
        $institutionId = $this->resolveScope($request, $claims);

        return response()->json(['data' => $this->reporting->dashboard($institutionId)]);
    }

    public function refresh(Request $request)
    {
        return $this->dashboard($request);
    }

    // ─── Recherche ─────────────────────────────────────────────────────

    public function searchAssets(Request $request)
    {
        $claims = AuthenticateUser::claims($request);
        $scope = $claims['institution_id'] ?? null;

        $filters = $request->only(['q', 'institution_id', 'category_id', 'state', 'entry_mode', 'page', 'per_page']);
        // Le perimetre du jeton prime sur le filtre demande.
        if ($scope !== null) {
            $filters['institution_id'] = $scope;
        }

        return AssetProjectionResource::collection(
            $this->reporting->searchAssets($filters, null)
        );
    }

    public function assetDetail(Request $request, string $assetId)
    {
        $claims = AuthenticateUser::claims($request);
        $detail = $this->reporting->assetDetail($assetId, $claims['institution_id'] ?? null);

        if ($detail === null) {
            throw new DomainException('ASSET_PROJECTION_NOT_FOUND', 'Bien non projete.');
        }

        return response()->json([
            'data' => [
                'projection' => (new AssetProjectionResource($detail['projection']))->resolve($request),
                'chronology' => $detail['chronology']->map(fn ($e) => [
                    'event_type' => $e->event_type,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                    'producer' => $e->producer,
                    'correlation_id' => $e->correlation_id,
                ])->all(),
            ],
        ]);
    }

    // ─── Indicateurs ───────────────────────────────────────────────────

    public function indicators(Request $request)
    {
        $claims = AuthenticateUser::claims($request);

        return response()->json([
            'data' => $this->reporting->indicators($claims['institution_id'] ?? null),
        ]);
    }

    // ─── Vues ──────────────────────────────────────────────────────────

    public function views(Request $request)
    {
        $claims = AuthenticateUser::claims($request);

        return SavedViewResource::collection(
            collect($this->reporting->viewsFor($claims['sub']))
        );
    }

    public function createView(CreateView $request): SavedViewResource
    {
        $claims = AuthenticateUser::claims($request);
        $view = $this->reporting->createView(
            $claims['sub'],
            $request->validated(),
            $request->header('X-Correlation-ID'),
        );

        return new SavedViewResource($view);
    }

    public function deleteView(Request $request, SavedView $view): Response
    {
        $claims = AuthenticateUser::claims($request);
        $this->reporting->deleteView($view, $claims['sub']);

        return response()->noContent();
    }

    // ─── Rapports ──────────────────────────────────────────────────────

    public function templates(Request $request)
    {
        return ReportTemplateResource::collection(
            collect($this->reporting->templates())
        );
    }

    public function runReport(RunReport $request, string $templateCode): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $run = $this->reporting->runReport(
            $templateCode,
            $request->validated(),
            $claims['institution_id'] ?? null,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return (new ReportRunResource($run))->response()->setStatusCode(201);
    }

    public function reportRuns(Request $request)
    {
        $claims = AuthenticateUser::claims($request);
        $query = ReportRun::query()->orderByDesc('created_at');

        // Diffusion controlee : perimetre du jeton.
        if (($claims['institution_id'] ?? null) !== null) {
            $query->where('institution_id', $claims['institution_id']);
        }
        if ($request->query('template_code')) {
            $query->where('template_code', $request->query('template_code'));
        }
        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        return ReportRunResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function reportRun(Request $request, ReportRun $reportRun): ReportRunDetailResource
    {
        $claims = AuthenticateUser::claims($request);
        if (($claims['institution_id'] ?? null) !== null
            && $reportRun->institution_id !== null
            && $reportRun->institution_id !== $claims['institution_id']) {
            throw new DomainException('FORBIDDEN', 'Rapport hors du perimetre institutionnel.');
        }

        return new ReportRunDetailResource($reportRun);
    }

    public function exportReportRun(Request $request, ReportRun $reportRun)
    {
        $claims = AuthenticateUser::claims($request);
        if (($claims['institution_id'] ?? null) !== null
            && $reportRun->institution_id !== null
            && $reportRun->institution_id !== $claims['institution_id']) {
            throw new DomainException('FORBIDDEN', 'Rapport hors du perimetre institutionnel.');
        }
        if ($reportRun->status !== 'completed' || $reportRun->result === null) {
            throw new DomainException('REPORT_NOT_READY', 'Le rapport n\'est pas encore disponible.');
        }

        $format = $request->query('format', 'json');

        if ($format === 'csv') {
            $rows = $reportRun->result['rows'] ?? [];
            $filename = $reportRun->reference.'.csv';

            return response()->streamDownload(function () use ($rows): void {
                $out = fopen('php://output', 'w');
                if ($rows !== []) {
                    fputcsv($out, array_keys($rows[0]));
                    foreach ($rows as $row) {
                        fputcsv($out, array_map(
                            fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v,
                            $row
                        ));
                    }
                }
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['data' => $reportRun->result]);
    }

    /**
     * Perimetre : institution du jeton, ou parametre national explicite
     * pour les comptes centraux.
     */
    private function resolveScope(Request $request, array $claims): ?string
    {
        $institutionId = $claims['institution_id'] ?? null;
        $requested = $request->query('institution_id');

        if ($requested !== null && $institutionId !== null && $requested !== $institutionId) {
            throw new DomainException('FORBIDDEN', 'Tableau de bord hors perimetre.');
        }

        return $institutionId ?? ($requested !== null ? (string) $requested : null);
    }
}
