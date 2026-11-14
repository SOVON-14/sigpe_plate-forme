<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateUser;
use App\Http\Requests\AddDocumentVersion;
use App\Http\Requests\CreateAccessRequest;
use App\Http\Requests\StoreDocument;
use App\Http\Requests\TransitionDocument;
use App\Http\Requests\ValidateDocument;
use App\Http\Resources\AccessRequestResource;
use App\Http\Resources\DocumentDetailResource;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request)
    {
        $query = Document::query()->with('category')->withCount('versions');
        $institutionId = AuthenticateUser::claims($request)['institution_id'] ?? null;

        if ($institutionId !== null) {
            // Perimetre institutionnel : documents de l'institution
            // + documents nationaux (institution_id null).
            $query->where(fn (Builder $q) => $q
                ->where('institution_id', $institutionId)
                ->orWhereNull('institution_id'));
        }

        foreach (['category_id', 'confidentiality', 'lifecycle_stage', 'asset_id'] as $filter) {
            if ($request->query($filter) !== null) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->query('q') !== null) {
            $q = '%'.$request->query('q').'%';
            $query->where(fn (Builder $w) => $w
                ->where('title', 'like', $q)
                ->orWhere('reference', 'like', $q)
                ->orWhere('issuer', 'like', $q));
        }

        return DocumentResource::collection(
            $query->orderByDesc('created_at')->paginate(min((int) $request->query('per_page', 25), 100))
        );
    }

    public function store(StoreDocument $request): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $document = $this->documents->createDocument(
            $request->validated(),
            $request->file('file'),
            $claims['sub'] ?? null,
            $claims['institution_id'] ?? null,
            $request->header('X-Correlation-ID'),
        );

        return (new DocumentDetailResource($document))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Document $document): DocumentDetailResource
    {
        $this->documents->logView($document, AuthenticateUser::claims($request)['sub'] ?? null, $request->ip());

        return new DocumentDetailResource($document->load('versions', 'category'));
    }

    public function versions(Document $document): JsonResponse
    {
        return response()->json([
            'data' => $document->versions()->orderBy('version_number')->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'document_id' => $v->document_id,
                    'version_number' => $v->version_number,
                    'original_filename' => $v->original_filename,
                    'mime_type' => $v->mime_type,
                    'size_bytes' => $v->size_bytes,
                    'sha256' => $v->sha256,
                    'status' => $v->status->value,
                    'uploaded_by' => $v->uploaded_by,
                    'created_at' => $v->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function addVersion(AddDocumentVersion $request, Document $document): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $updated = $this->documents->addVersion(
            $document,
            $request->file('file'),
            $claims['sub'] ?? null,
            ['erratum_motif' => $request->input('erratum_motif')],
            $request->header('X-Correlation-ID'),
        );

        return (new DocumentDetailResource($updated))
            ->response()
            ->setStatusCode(201);
    }

    public function validate(ValidateDocument $request, Document $document): DocumentDetailResource
    {
        $claims = AuthenticateUser::claims($request);
        $updated = $this->documents->validate(
            $document,
            $request->input('version_id'),
            $request->input('comment'),
            $claims['sub'] ?? null,
            $request->header('X-Correlation-ID'),
        );

        return new DocumentDetailResource($updated);
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $claims = AuthenticateUser::claims($request);
        $result = $this->documents->authorizedDownload(
            $document,
            $request->query('version_id'),
            $claims['sub'] ?? 'anonymous',
            $claims['permissions'] ?? [],
            $request->ip(),
            $request->header('X-Correlation-ID'),
        );

        return $result['response'];
    }

    public function lifecycle(TransitionDocument $request, Document $document): DocumentDetailResource
    {
        $claims = AuthenticateUser::claims($request);
        $updated = $this->documents->transitionLifecycle(
            $document,
            $request->input('target_stage'),
            $request->input('motif'),
            $claims['sub'] ?? null,
            in_array('document.eliminate', $claims['permissions'] ?? [], true),
            $request->header('X-Correlation-ID'),
        );

        return new DocumentDetailResource($updated);
    }

    public function accessRequests(Request $request, Document $document)
    {
        return AccessRequestResource::collection(
            $document->accessRequests()->orderByDesc('created_at')->get()
        );
    }

    public function createAccessRequest(CreateAccessRequest $request, Document $document): JsonResponse
    {
        $claims = AuthenticateUser::claims($request);
        $accessRequest = $this->documents->createAccessRequest(
            $document,
            $request->validated(),
            $claims['sub'] ?? throw new DomainException('UNAUTHENTICATED', 'Authentification requise.'),
            $request->header('X-Correlation-ID'),
        );

        return (new AccessRequestResource($accessRequest))
            ->response()
            ->setStatusCode(201);
    }

    public function accessLog(Document $document)
    {
        return response()->json([
            'data' => DocumentAccessLog::where('document_id', $document->id)
                ->orderByDesc('created_at')->limit(200)->get()
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'document_id' => $l->document_id,
                    'version_id' => $l->version_id,
                    'actor_id' => $l->actor_id,
                    'action' => $l->action->value,
                    'ip_address' => $l->ip_address,
                    'created_at' => $l->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }
}
