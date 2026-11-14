<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use App\Enums\FindingResult;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResponses;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\AssignAgentRequest;
use App\Http\Requests\ChangeCampaignStatusRequest;
use App\Http\Requests\RecordFindingRequest;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Requests\UnassignAgentRequest;
use App\Http\Resources\CampaignAgentResource;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\FindingResource;
use App\Models\InventoryCampaign;
use App\Models\InventoryCampaignAgent;
use App\Services\FindingService;
use App\Services\InventoryCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    use PaginatesResponses;

    public function __construct(
        private readonly InventoryCampaignService $campaigns,
        private readonly FindingService $findings,
    ) {}

    public function index(Request $request, string $institutionId): JsonResponse
    {
        $query = InventoryCampaign::forInstitution($institutionId)
            ->withCounters()
            ->orderByDesc('created_at');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($from = $request->query('period_from')) {
            $query->where('period_end', '>=', $from);
        }
        if ($to = $request->query('period_to')) {
            $query->where('period_start', '<=', $to);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            CampaignResource::class,
        );
    }

    public function store(StoreCampaignRequest $request, string $institutionId): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $campaign = $this->campaigns->create(
            [
                'designation' => $request->validated('designation'),
                'type' => CampaignType::from($request->validated('type')),
                'period_start' => $request->validated('period_start'),
                'period_end' => $request->validated('period_end'),
                'responsible_account_id' => $request->validated('responsible_account_id'),
                'organizational_unit_ids' => $request->validated('organizational_unit_ids'),
                'location' => $request->validated('location'),
                'expected_count' => $request->validated('expected_count'),
            ],
            $institutionId,
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new CampaignResource($campaign->loadCount('findings')))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/inventory-campaigns/{$campaign->id}")
            ->header('ETag', '"'.$campaign->updated_at->timestamp.'"');
    }

    public function show(InventoryCampaign $campaign): CampaignResource
    {
        return new CampaignResource($campaign->loadCount([
            'findings as recorded_count',
            'findings as found_count' => fn ($q) => $q->where('result', 'trouve'),
            'findings as not_found_count' => fn ($q) => $q->where('result', 'non_trouve'),
            'findings as anomalies_count' => fn ($q) => $q->whereNotNull('anomaly_type'),
            'findings as pending_count' => fn ($q) => $q->where('verification_status', 'pending'),
        ]));
    }

    public function changeStatus(ChangeCampaignStatusRequest $request, InventoryCampaign $campaign): CampaignResource
    {
        $claims = AuthenticateUser::claims($request);

        return new CampaignResource($this->campaigns->changeStatus(
            $campaign,
            CampaignStatus::from($request->validated('status')),
            $request->validated('motif'),
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        )->fresh());
    }

    public function agents(Request $request, InventoryCampaign $campaign): JsonResponse
    {
        return response()->json([
            'data' => CampaignAgentResource::collection(
                $campaign->agents()->orderByDesc('assigned_at')->get(),
            )->resolve(),
        ]);
    }

    public function assignAgent(AssignAgentRequest $request, InventoryCampaign $campaign): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $agent = $this->campaigns->assignAgent(
            $campaign,
            $request->validated('functional_account_id'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new CampaignAgentResource($agent))
            ->response()
            ->setStatusCode(201);
    }

    public function unassignAgent(UnassignAgentRequest $request, InventoryCampaign $campaign, InventoryCampaignAgent $agent): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $this->campaigns->unassignAgent(
            $campaign,
            $agent,
            $request->validated('motif'),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return response()->json(null, 204);
    }

    public function findings(Request $request, InventoryCampaign $campaign): JsonResponse
    {
        $query = $campaign->findings()->orderByDesc('recorded_at');

        if ($result = $request->query('result')) {
            $query->where('result', $result);
        }
        if ($anomaly = $request->query('anomaly_type')) {
            $query->where('anomaly_type', $anomaly);
        }
        if ($status = $request->query('verification_status')) {
            $query->where('verification_status', $status);
        }

        return $this->paginated(
            $query->paginate(min((int) $request->query('per_page', 20), 100)),
            FindingResource::class,
        );
    }

    public function recordFinding(RecordFindingRequest $request, InventoryCampaign $campaign): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);

        $finding = $this->findings->record(
            $campaign,
            $request->validated(),
            $claims['sub'],
            ['type' => 'user', 'id' => $claims['sub']],
            $request->header('X-Correlation-ID'),
        );

        return (new FindingResource($finding))
            ->response()
            ->setStatusCode(201)
            ->header('Location', "/api/v1/findings/{$finding->id}");
    }

    public function report(InventoryCampaign $campaign): JsonResponse
    {
        $findings = $campaign->findings()->get();

        $byResult = $findings->countBy(fn ($f) => $f->result->value)->all();
        $byAnomaly = $findings->filter(fn ($f) => $f->anomaly_type !== null)
            ->countBy(fn ($f) => $f->anomaly_type->value)->all();

        return response()->json([
            'campaign_id' => $campaign->id,
            'institution_id' => $campaign->institution_id,
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'expected' => $campaign->expected_count ?? 0,
                'recorded' => $findings->count(),
                'found' => $findings->where('result', FindingResult::Found)->count(),
                'not_found' => $findings->where('result', FindingResult::NotFound)->count(),
                'anomalies' => $findings->filter(fn ($f) => $f->anomaly_type !== null)->count(),
            ],
            'by_result' => $byResult,
            'by_anomaly' => $byAnomaly,
            'pending_verification' => $findings
                ->filter(fn ($f) => $f->verification_status->value === 'pending')
                ->pluck('id')->values()->all(),
        ]);
    }
}
