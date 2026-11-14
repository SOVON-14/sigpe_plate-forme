<?php

namespace App\Services;

use App\Enums\AccessAction;
use App\Enums\AccessRequestStatus;
use App\Enums\Confidentiality;
use App\Enums\LifecycleStage;
use App\Enums\VersionStatus;
use App\Exceptions\DomainException;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentAccessRequest;
use App\Models\DocumentCategory;
use App\Models\DocumentSequence;
use App\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gestion documentaire SIGPE (CDC §4.9, catalogue §8.3).
 *
 * Regles absolues :
 * - toutes les versions sont conservees, jamais d'ecrasement ;
 * - un document valide ne peut etre corrige que par une nouvelle
 *   version avec motif d'erratum ;
 * - elimination = seul le fichier est retire, metadonnees conservees ;
 * - acces aux niveaux confidentiel/hautement_sensible : habilitation
 *   permanente (permission document.read_sensitive) OU access grant
 *   approuve et non expire ;
 * - chaque consultation/telechargement est journalise et publie.
 */
class DocumentService
{
    private const NATIONAL_CATEGORIES = [
        ['code' => 'acquisition', 'label' => 'Documents d\'acquisition'],
        ['code' => 'propriete', 'label' => 'Documents de propriete'],
        ['code' => 'technique', 'label' => 'Documents techniques'],
        ['code' => 'administratif', 'label' => 'Documents administratifs'],
        ['code' => 'maintenance', 'label' => 'Documents de maintenance'],
        ['code' => 'inventaire', 'label' => 'Documents d\'inventaire'],
        ['code' => 'financier', 'label' => 'Documents financiers'],
        ['code' => 'reforme', 'label' => 'Documents de reforme / sortie'],
    ];

    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'text/plain',
    ];

    private const MAX_SIZE_BYTES = 25 * 1024 * 1024;

    private const DISK = 'local';

    public function __construct(private readonly OutboxRecorder $outbox) {}

    /**
     * Garantit la presence des 8 categories nationales (idempotent).
     *
     * @return Collection<int, DocumentCategory>
     */
    public function nationalCategories(): Collection
    {
        foreach (self::NATIONAL_CATEGORIES as $cat) {
            DocumentCategory::firstOrCreate(
                ['code' => $cat['code'], 'institution_id' => null],
                ['label' => $cat['label'], 'scope' => 'national', 'is_active' => true],
            );
        }

        return DocumentCategory::where('is_active', true)->orderBy('code')->get();
    }

    /**
     * Cree un document + version 1. $file null = reference_only
     * (metadonnees seules, fichier importe ulterieurement).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDocument(array $data, ?UploadedFile $file, ?string $actorId, ?string $institutionId, ?string $correlationId = null): Document
    {
        $category = DocumentCategory::whereKey($data['category_id'])->where('is_active', true)->first();
        if ($category === null) {
            throw new DomainException('CATEGORY_NOT_FOUND', 'Categorie documentaire introuvable ou inactive.');
        }

        if ($category->scope === 'local' && $institutionId !== null
            && $category->institution_id !== $institutionId) {
            throw new DomainException('FORBIDDEN', 'Categorie locale hors perimetre institutionnel.');
        }

        $confidentiality = Confidentiality::tryFrom($data['confidentiality'] ?? 'interne') ?? Confidentiality::Interne;

        return DB::transaction(function () use ($data, $file, $actorId, $institutionId, $correlationId, $category, $confidentiality): Document {
            $document = Document::create([
                'reference' => $this->nextReference(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'category_id' => $category->id,
                'institution_id' => $data['institution_id'] ?? $institutionId,
                'unit_id' => $data['unit_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'confidentiality' => $confidentiality,
                'lifecycle_stage' => LifecycleStage::Active,
                'retention_until' => $data['retention_until'] ?? null,
                'document_date' => $data['document_date'] ?? null,
                'issuer' => $data['issuer'] ?? null,
                'uploaded_by' => $actorId,
            ]);

            if ($file !== null) {
                $version = $this->storeVersion($document, $file, $actorId);
                $document->update(['current_version' => $version->version_number]);

                $this->outbox->record(
                    'document.version_added',
                    'document',
                    $document->id,
                    [
                        'document_id' => $document->id,
                        'version_id' => $version->id,
                        'version_number' => $version->version_number,
                        'original_filename' => $version->original_filename,
                        'mime_type' => $version->mime_type,
                        'size_bytes' => $version->size_bytes,
                        'sha256' => $version->sha256,
                        'previous_version_id' => null,
                        'uploaded_by' => $version->uploaded_by,
                        'created_at' => $version->created_at->toIso8601String(),
                    ],
                    correlationId: $correlationId,
                    institutionId: $document->institution_id,
                    actor: $this->actor($actorId),
                );
            }

            $this->outbox->record(
                'document.created',
                'document',
                $document->id,
                [
                    'document_id' => $document->id,
                    'reference' => $document->reference,
                    'title' => $document->title,
                    'category_id' => $document->category_id,
                    'institution_id' => $document->institution_id,
                    'asset_id' => $document->asset_id,
                    'confidentiality' => $document->confidentiality->value,
                    'has_file' => $file !== null,
                    'uploaded_by' => $document->uploaded_by,
                    'created_at' => $document->created_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $document->institution_id,
                actor: $this->actor($actorId),
            );

            return $document->fresh(['versions', 'category']);
        });
    }

    /**
     * Ajoute une version — jamais d'ecrasement. Si la version
     * courante est validee, un motif d'erratum est obligatoire.
     *
     * @param  array{erratum_motif?: ?string}  $options
     */
    public function addVersion(Document $document, UploadedFile $file, ?string $actorId, array $options = [], ?string $correlationId = null): Document
    {
        if ($document->lifecycle_stage === LifecycleStage::Eliminated) {
            throw new DomainException('DOCUMENT_ELIMINATED', 'Document elimine : aucune nouvelle version possible.');
        }

        $current = $document->versions()->where('version_number', $document->current_version)->first();
        if ($current !== null && $current->status === VersionStatus::Validated
            && empty($options['erratum_motif'])) {
            throw new DomainException('ERRATUM_REQUIRED', 'La version courante est validee : un motif d\'erratum est requis pour une nouvelle version.');
        }

        return DB::transaction(function () use ($document, $file, $actorId, $options, $correlationId): Document {
            $previous = $document->versions()->where('version_number', $document->current_version)->first();
            if ($previous !== null && $previous->status === VersionStatus::Current) {
                $previous->update(['status' => VersionStatus::Superseded]);
            }

            $version = $this->storeVersion($document, $file, $actorId);
            $document->update(['current_version' => $version->version_number]);

            $this->outbox->record(
                'document.version_added',
                'document',
                $document->id,
                [
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                    'version_number' => $version->version_number,
                    'original_filename' => $version->original_filename,
                    'mime_type' => $version->mime_type,
                    'size_bytes' => $version->size_bytes,
                    'sha256' => $version->sha256,
                    'previous_version_id' => $previous?->id,
                    'erratum_motif' => $options['erratum_motif'] ?? null,
                    'uploaded_by' => $version->uploaded_by,
                    'created_at' => $version->created_at->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $document->institution_id,
                actor: $this->actor($actorId),
            );

            return $document->fresh(['versions', 'category']);
        });
    }

    /**
     * Validation interne electronique (CDC §4.9) — distincte de la
     * signature electronique juridiquement reconnue.
     */
    public function validate(Document $document, ?string $versionId, ?string $comment, ?string $actorId, ?string $correlationId = null): Document
    {
        if ($document->lifecycle_stage === LifecycleStage::Eliminated) {
            throw new DomainException('DOCUMENT_ELIMINATED', 'Document elimine.');
        }

        $version = $versionId !== null
            ? $document->versions()->whereKey($versionId)->first()
            : $document->versions()->where('version_number', $document->current_version)->first();

        if ($version === null) {
            throw new DomainException('VERSION_NOT_FOUND', 'Version introuvable pour ce document.');
        }
        if ($version->status === VersionStatus::Validated) {
            throw new DomainException('ALREADY_VALIDATED', 'Cette version est deja validee.');
        }

        return DB::transaction(function () use ($document, $version, $comment, $actorId, $correlationId): Document {
            $version->update(['status' => VersionStatus::Validated]);
            $document->update(['validated_at' => now(), 'validated_by' => $actorId]);

            $this->outbox->record(
                'document.validated',
                'document',
                $document->id,
                [
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                    'validated_by' => $actorId,
                    'comment' => $comment,
                    'validated_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $document->institution_id,
                actor: $this->actor($actorId),
            );

            return $document->fresh(['versions', 'category']);
        });
    }

    /**
     * Transition du cycle de vie archivistique.
     */
    public function transitionLifecycle(Document $document, string $targetStage, string $motif, ?string $actorId, bool $canEliminate, ?string $correlationId = null): Document
    {
        $target = LifecycleStage::tryFrom($targetStage);
        if ($target === null) {
            throw new DomainException('INVALID_STAGE', 'Etape de cycle de vie inconnue.');
        }

        $from = $document->lifecycle_stage;
        if (! in_array($target->value, $from->allowedTargets(), true)) {
            throw new DomainException('INVALID_TRANSITION', "Transition {$from->value} -> {$target->value} non autorisee.");
        }
        if ($target === LifecycleStage::Eliminated && ! $canEliminate) {
            throw new DomainException('FORBIDDEN', 'Permission document.eliminate requise pour l\'elimination.');
        }

        return DB::transaction(function () use ($document, $from, $target, $motif, $actorId, $correlationId): Document {
            $document->update(['lifecycle_stage' => $target]);

            if ($target === LifecycleStage::Eliminated) {
                // Seul le fichier est retire ; metadonnees et
                // historique des versions sont conserves.
                foreach ($document->versions as $version) {
                    Storage::disk(self::DISK)->delete($version->storage_path);
                }
            }

            $this->outbox->record(
                'document.lifecycle_changed',
                'document',
                $document->id,
                [
                    'document_id' => $document->id,
                    'from_stage' => $from->value,
                    'to_stage' => $target->value,
                    'motif' => $motif,
                    'changed_by' => $actorId,
                    'changed_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $document->institution_id,
                actor: $this->actor($actorId),
            );

            return $document->fresh(['versions', 'category']);
        });
    }

    /**
     * Cree une demande d'acces exceptionnel (confidentiel+).
     */
    public function createAccessRequest(Document $document, array $data, string $requesterId, ?string $correlationId = null): DocumentAccessRequest
    {
        if ($document->lifecycle_stage === LifecycleStage::Eliminated) {
            throw new DomainException('DOCUMENT_ELIMINATED', 'Document elimine.');
        }

        $requestedUntil = Carbon::parse($data['requested_until']);
        if ($requestedUntil->isPast()) {
            throw new DomainException('INVALID_REQUEST', 'La date de fin demandee est passee.');
        }

        $existing = DocumentAccessRequest::where('document_id', $document->id)
            ->where('requester_id', $requesterId)
            ->where('status', AccessRequestStatus::Pending)
            ->exists();
        if ($existing) {
            throw new DomainException('REQUEST_ALREADY_PENDING', 'Une demande est deja en attente pour ce document.');
        }

        return DB::transaction(function () use ($document, $data, $requesterId, $requestedUntil, $correlationId): DocumentAccessRequest {
            $request = DocumentAccessRequest::create([
                'document_id' => $document->id,
                'requester_id' => $requesterId,
                'motif' => $data['motif'],
                'scope' => $data['scope'] ?? 'view',
                'requested_until' => $requestedUntil,
                'status' => AccessRequestStatus::Pending,
            ]);

            $this->logAccess($document, null, $requesterId, AccessAction::AccessRequest, null, $request->id);
            $this->outbox->record(
                'document.access_logged',
                'document',
                $document->id,
                [
                    'document_id' => $document->id,
                    'action' => 'access_request',
                    'actor_id' => $requesterId,
                    'confidentiality' => $document->confidentiality->value,
                    'logged_at' => now()->toIso8601String(),
                ],
                correlationId: $correlationId,
                institutionId: $document->institution_id,
                actor: $this->actor($requesterId),
            );

            return $request;
        });
    }

    /**
     * Decision sur une demande d'acces — le decideur ne peut pas etre
     * le demandeur (segregation des taches).
     */
    public function decideAccessRequest(DocumentAccessRequest $request, string $decision, string $motif, string $deciderId, ?string $correlationId = null): DocumentAccessRequest
    {
        if ($request->status !== AccessRequestStatus::Pending) {
            throw new DomainException('REQUEST_NOT_PENDING', 'Cette demande n\'est plus en attente.');
        }
        if ($request->requester_id === $deciderId) {
            throw new DomainException('SELF_APPROVAL_FORBIDDEN', 'Le demandeur ne peut pas decider de sa propre demande.');
        }

        $approved = $decision === 'approved';
        $request->update([
            'status' => $approved ? AccessRequestStatus::Approved : AccessRequestStatus::Denied,
            'decided_by' => $deciderId,
            'decided_at' => now(),
            'decision_motif' => $motif,
        ]);

        $document = $request->document;
        $this->logAccess($document, null, $deciderId, AccessAction::Decision, null, $request->id);
        $this->outbox->record(
            'document.access_logged',
            'document',
            $document->id,
            [
                'document_id' => $document->id,
                'action' => 'decision',
                'actor_id' => $deciderId,
                'confidentiality' => $document->confidentiality->value,
                'via_grant_id' => $request->id,
                'logged_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $document->institution_id,
            actor: $this->actor($deciderId),
        );

        return $request->fresh();
    }

    /**
     * Retourne le fichier si l'appelant est habilite ; journalise
     * toujours la tentative.
     *
     * @return array{response: StreamedResponse, grant: ?DocumentAccessRequest}
     */
    public function authorizedDownload(Document $document, ?string $versionId, string $actorId, array $permissions, ?string $ip, ?string $correlationId = null): array
    {
        if ($document->lifecycle_stage === LifecycleStage::Eliminated) {
            throw new DomainException('DOCUMENT_ELIMINATED', 'Document elimine : fichier indisponible.');
        }

        $version = $versionId !== null
            ? $document->versions()->whereKey($versionId)->first()
            : $document->versions()->where('version_number', $document->current_version)->first();

        if ($version === null) {
            throw new DomainException('VERSION_NOT_FOUND', 'Version introuvable.');
        }
        if (! Storage::disk(self::DISK)->exists($version->storage_path)) {
            throw new DomainException('FILE_MISSING', 'Fichier absent du stockage.');
        }

        $grant = null;
        if ($document->confidentiality->requiresGrant()
            && ! in_array('document.read_sensitive', $permissions, true)) {
            $grant = DocumentAccessRequest::where('document_id', $document->id)
                ->where('requester_id', $actorId)
                ->where('status', AccessRequestStatus::Approved)
                ->where('requested_until', '>', now())
                ->whereIn('scope', ['view', 'download'])
                ->latest('decided_at')
                ->first();

            if ($grant === null) {
                $this->logAccess($document, $version->id, $actorId, AccessAction::Download, $ip, null, false);
                throw new DomainException('ACCESS_GRANT_REQUIRED', 'Acces exceptionnel approuve requis pour ce niveau de confidentialite.');
            }
        }

        $this->logAccess($document, $version->id, $actorId, AccessAction::Download, $ip, $grant?->id);
        $this->outbox->record(
            'document.access_logged',
            'document',
            $document->id,
            [
                'document_id' => $document->id,
                'version_id' => $version->id,
                'action' => 'download',
                'actor_id' => $actorId,
                'confidentiality' => $document->confidentiality->value,
                'via_grant_id' => $grant?->id,
                'logged_at' => now()->toIso8601String(),
            ],
            correlationId: $correlationId,
            institutionId: $document->institution_id,
            actor: $this->actor($actorId),
        );

        $path = $version->storage_path;
        $filename = $version->original_filename;
        $mime = $version->mime_type;

        $response = Storage::disk(self::DISK)->download($path, $filename, ['Content-Type' => $mime]);

        return ['response' => $response, 'grant' => $grant];
    }

    /**
     * Journalise une consultation simple (GET show) — action 'view'.
     */
    public function logView(Document $document, ?string $actorId, ?string $ip): void
    {
        $this->logAccess($document, null, $actorId, AccessAction::View, $ip);
    }

    /**
     * @param  array{type?: string, id?: string}|null  $actor
     */
    private function logAccess(Document $document, ?string $versionId, ?string $actorId, AccessAction $action, ?string $ip, ?string $requestId = null, bool $success = true): DocumentAccessLog
    {
        return DocumentAccessLog::create([
            'document_id' => $document->id,
            'version_id' => $versionId,
            'actor_id' => $actorId,
            'action' => $action,
            'ip_address' => $ip,
            'access_request_id' => $requestId,
        ]);
    }

    private function storeVersion(Document $document, UploadedFile $file, ?string $actorId): DocumentVersion
    {
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new DomainException('UNSUPPORTED_MEDIA_TYPE', "Type MIME non autorise : {$mime}.");
        }
        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw new DomainException('FILE_TOO_LARGE', 'Fichier superieur a 25 Mo.');
        }

        $nextNumber = $document->current_version + 1;
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName()) ?: 'file';
        $path = "documents/{$document->id}/v{$nextNumber}/{$safeName}";

        $contents = file_get_contents($file->getRealPath());
        Storage::disk(self::DISK)->put($path, $contents);

        return DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => $nextNumber,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'sha256' => hash('sha256', $contents),
            'storage_path' => $path,
            'status' => VersionStatus::Current,
            'uploaded_by' => $actorId,
        ]);
    }

    private function nextReference(): string
    {
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($year): string {
            $sequence = DocumentSequence::where('year', $year)->lockForUpdate()->first();
            if ($sequence === null) {
                $sequence = DocumentSequence::create(['year' => $year, 'last_number' => 0]);
            }
            $sequence->increment('last_number');
            $sequence->refresh();

            return sprintf('DOC-%d-%06d', $year, $sequence->last_number);
        });
    }

    /**
     * @return array{type: string, id: string}|null
     */
    private function actor(?string $actorId): ?array
    {
        return $actorId !== null ? ['type' => 'user', 'id' => $actorId] : null;
    }
}
