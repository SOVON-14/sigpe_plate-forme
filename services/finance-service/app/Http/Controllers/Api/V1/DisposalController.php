<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\CancelDisposal;
use App\Http\Requests\ResolveInvestigation;
use App\Http\Requests\StoreDisposal;
use App\Http\Requests\SubmitDisposal;
use App\Http\Resources\DisposalHistoryResource;
use App\Http\Resources\DisposalResource;
use App\Models\Disposal;
use App\Services\FinanceService;
use Illuminate\Http\Request;

class DisposalController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function index(Request $request)
    {
        $query = Disposal::query()->orderByDesc('created_at');

        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;
        if ($institutionId !== null) {
            $query->where('institution_id', $institutionId);
        }
        foreach (['asset_id', 'institution_id', 'status', 'disposal_type'] as $filter) {
            if ($request->query($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }

        return DisposalResource::collection(
            $query->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreDisposal $request): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);
        $disposal = $this->finance->createDisposal(
            $request->validated(),
            $claims['institution_id'] ?? null,
            $claims['sub'],
            $request->header('X-Correlation-ID'),
        );

        return new DisposalResource($disposal);
    }

    public function show(Disposal $disposal): DisposalResource
    {
        return new DisposalResource($disposal);
    }

    public function submit(SubmitDisposal $request, Disposal $disposal): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);

        return new DisposalResource(
            $this->finance->submit($disposal, $request->validated('workflow_instance_id'), $claims['sub'])
        );
    }

    public function execute(Request $request, Disposal $disposal): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);

        return new DisposalResource(
            $this->finance->execute($disposal, $claims['sub'], $request->header('X-Correlation-ID'))
        );
    }

    public function close(Request $request, Disposal $disposal): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);

        return new DisposalResource(
            $this->finance->close($disposal, $claims['sub'], $request->header('X-Correlation-ID'))
        );
    }

    public function resolveInvestigation(ResolveInvestigation $request, Disposal $disposal): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);

        return new DisposalResource(
            $this->finance->resolveInvestigation($disposal, $request->validated(), $claims['sub'])
        );
    }

    public function cancel(CancelDisposal $request, Disposal $disposal): DisposalResource
    {
        $claims = AuthenticateUser::claims($request);

        return new DisposalResource(
            $this->finance->cancel($disposal, $request->validated('reason'), $claims['sub'])
        );
    }

    public function history(Disposal $disposal)
    {
        return DisposalHistoryResource::collection(
            $disposal->histories()->orderBy('created_at')->get()
        );
    }
}
