<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Services\DocumentService;
use App\Services\TokenValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private string $institutionId;

    private string $otherInstitutionId;

    private string $sub;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->institutionId = (string) Str::uuid7();
        $this->otherInstitutionId = (string) Str::uuid7();
        $this->sub = (string) Str::uuid7();
        $this->app->make(DocumentService::class)->nationalCategories();
    }

    private function auth(array $claims = []): array
    {
        $merged = array_merge([
            'sub' => $this->sub,
            'permissions' => [
                'document.read',
                'document.manage',
                'document.decide',
                'document.eliminate',
                'document.read_sensitive',
            ],
            'institution_id' => $this->institutionId,
        ], $claims);

        $this->app->instance(TokenValidator::class, new class($merged) implements TokenValidator
        {
            public function __construct(private array $claims) {}

            public function validate(string $token): array
            {
                return $this->claims;
            }
        });

        return ['Authorization' => 'Bearer fake-token'];
    }

    private function category(string $code = 'propriete'): DocumentCategory
    {
        return DocumentCategory::where('code', $code)->whereNull('institution_id')->firstOrFail();
    }

    private function pdfFile(string $name = 'acte.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF\ncontenu-test-{$name}"
        );
    }

    private function createDocument(array $overrides = [], ?UploadedFile $file = null): Document
    {
        $payload = array_merge([
            'title' => 'Acte de propriete batiment A',
            'category_id' => $this->category()->id,
            'confidentiality' => 'interne',
        ], $overrides);

        if ($file !== null) {
            $payload['file'] = $file;
        }

        $response = $this->post('/api/v1/documents', $payload, $this->auth() + ['Idempotency-Key' => 'doc-'.Str::random(12)]);
        $response->assertCreated();

        return Document::findOrFail($response->json('data.id'));
    }

    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('service', 'document-service');
    }

    public function test_categories_lists_national_taxonomy(): void
    {
        $response = $this->getJson('/api/v1/document-categories', $this->auth())->assertOk();

        $codes = collect($response->json('data'))->pluck('code');
        foreach (['acquisition', 'propriete', 'technique', 'administratif', 'maintenance', 'inventaire', 'financier', 'reforme'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_create_document_with_file(): void
    {
        $document = $this->createDocument([], $this->pdfFile());

        $this->assertStringStartsWith('DOC-', $document->reference);
        $this->assertSame(1, $document->current_version);
        $this->assertSame('active', $document->lifecycle_stage->value);
        $this->assertSame(1, $document->versions()->count());
        $this->assertSame(64, strlen($document->versions()->first()->sha256));
        Storage::disk('local')->assertExists($document->versions()->first()->storage_path);

        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'document.created']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'document.version_added']);
    }

    public function test_create_metadata_only(): void
    {
        $document = $this->createDocument(['reference_only' => true]);

        $this->assertSame(0, $document->current_version);
        $this->assertSame(0, $document->versions()->count());
    }

    public function test_add_version_never_overwrites(): void
    {
        $document = $this->createDocument([], $this->pdfFile('v1.pdf'));

        $response = $this->post('/api/v1/documents/'.$document->id.'/versions', [
            'file' => $this->pdfFile('v2.pdf'),
        ], $this->auth() + ['Idempotency-Key' => 'ver-'.Str::random(12)]);

        $response->assertCreated();
        $document->refresh();

        $this->assertSame(2, $document->current_version);
        $versions = $document->versions()->orderBy('version_number')->get();
        $this->assertSame('superseded', $versions[0]->status->value);
        $this->assertSame('current', $versions[1]->status->value);
        $this->assertNotSame($versions[0]->sha256 === $versions[1]->sha256, true);
    }

    public function test_validate_then_new_version_requires_erratum(): void
    {
        $document = $this->createDocument([], $this->pdfFile());

        $this->postJson('/api/v1/documents/'.$document->id.'/validate', [], $this->auth()
            + ['Idempotency-Key' => 'val-'.Str::random(12)])->assertOk();

        $document->refresh();
        $this->assertNotNull($document->validated_at);
        $this->assertSame('validated', $document->versions()->first()->status->value);

        // Nouvelle version sans erratum -> 409
        $this->post('/api/v1/documents/'.$document->id.'/versions', [
            'file' => $this->pdfFile('v2.pdf'),
        ], $this->auth() + ['Idempotency-Key' => 'ver-'.Str::random(12)])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ERRATUM_REQUIRED');

        // Avec erratum -> 201
        $this->post('/api/v1/documents/'.$document->id.'/versions', [
            'file' => $this->pdfFile('v2.pdf'),
            'erratum_motif' => 'Correction de la date de signature',
        ], $this->auth() + ['Idempotency-Key' => 'ver-'.Str::random(12)])
            ->assertCreated();
    }

    public function test_lifecycle_transitions_and_elimination(): void
    {
        $document = $this->createDocument([], $this->pdfFile());
        $path = $document->versions()->first()->storage_path;

        $this->postJson('/api/v1/documents/'.$document->id.'/lifecycle', [
            'target_stage' => 'archives_intermediaires',
            'motif' => 'Fin de cycle actif',
        ], $this->auth() + ['Idempotency-Key' => 'lc-'.Str::random(12)])->assertOk();

        $this->postJson('/api/v1/documents/'.$document->id.'/lifecycle', [
            'target_stage' => 'archives_definitives',
            'motif' => 'Archivage definitif',
        ], $this->auth() + ['Idempotency-Key' => 'lc-'.Str::random(12)])->assertOk();

        // Saut interdit : active -> eliminated
        $other = $this->createDocument(['title' => 'Autre document'], $this->pdfFile());
        $this->postJson('/api/v1/documents/'.$other->id.'/lifecycle', [
            'target_stage' => 'eliminated',
            'motif' => 'Tentative de saut',
        ], $this->auth() + ['Idempotency-Key' => 'lc-'.Str::random(12)])
            ->assertStatus(409);

        // Elimination autorisee : fichier retire, metadonnees conservees
        $this->postJson('/api/v1/documents/'.$document->id.'/lifecycle', [
            'target_stage' => 'eliminated',
            'motif' => 'Delai de conservation depasse',
        ], $this->auth() + ['Idempotency-Key' => 'lc-'.Str::random(12)])->assertOk();

        $document->refresh();
        $this->assertSame('eliminated', $document->lifecycle_stage->value);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(1, $document->versions()->count()); // metadonnees conservees
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'document.lifecycle_changed']);
    }

    public function test_elimination_requires_permission(): void
    {
        $document = $this->createDocument([], $this->pdfFile());
        // Forcer le document en archives_definitives via le service
        $service = $this->app->make(DocumentService::class);
        $service->transitionLifecycle($document, 'archives_intermediaires', 'test', $this->sub, true);
        $service->transitionLifecycle($document->fresh(), 'archives_definitives', 'test', $this->sub, true);

        $this->postJson('/api/v1/documents/'.$document->id.'/lifecycle', [
            'target_stage' => 'eliminated',
            'motif' => 'Delai depasse',
        ], $this->auth(['permissions' => ['document.read', 'document.manage']])
            + ['Idempotency-Key' => 'lc-'.Str::random(12)])
            ->assertForbidden();
    }

    public function test_confidential_download_requires_grant(): void
    {
        $document = $this->createDocument(
            ['confidentiality' => 'confidentiel'],
            $this->pdfFile(),
        );

        // Sans document.read_sensitive ni grant -> 403
        $this->get('/api/v1/documents/'.$document->id.'/download', $this->auth([
            'permissions' => ['document.read', 'document.manage'],
        ]))->assertForbidden()->assertJsonPath('code', 'ACCESS_GRANT_REQUIRED');

        // Avec habilitation permanente -> 200
        $this->get('/api/v1/documents/'.$document->id.'/download', $this->auth())
            ->assertOk();
    }

    public function test_access_request_flow(): void
    {
        $document = $this->createDocument(['confidentiality' => 'hautement_sensible'], $this->pdfFile());
        $requesterPerms = ['document.read', 'document.manage'];

        // Demande d'acces
        $response = $this->postJson('/api/v1/documents/'.$document->id.'/access-requests', [
            'motif' => 'Audit contradictoire programme',
            'requested_until' => now()->addDays(3)->toIso8601String(),
            'scope' => 'download',
        ], $this->auth(['permissions' => $requesterPerms])
            + ['Idempotency-Key' => 'ar-'.Str::random(12)]);
        $response->assertCreated();
        $requestId = $response->json('data.id');

        // Auto-approbation interdite
        $this->postJson('/api/v1/access-requests/'.$requestId.'/decision', [
            'decision' => 'approved',
            'motif' => 'Auto validation',
        ], $this->auth(['permissions' => ['document.read', 'document.decide']]))
            ->assertForbidden();

        // Approbation par un tiers
        $approverSub = (string) Str::uuid7();
        $this->postJson('/api/v1/access-requests/'.$requestId.'/decision', [
            'decision' => 'approved',
            'motif' => 'Besoin legitime verifie',
        ], $this->auth([
            'sub' => $approverSub,
            'permissions' => ['document.read', 'document.decide'],
        ]) + ['Idempotency-Key' => 'dec-'.Str::random(12)])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Telechargement avec grant actif
        $this->get('/api/v1/documents/'.$document->id.'/download', $this->auth([
            'permissions' => $requesterPerms,
        ]))->assertOk();

        // Journal d'acces alimenté
        $this->assertDatabaseHas('document_access_logs', [
            'document_id' => $document->id,
            'action' => 'download',
            'access_request_id' => $requestId,
        ]);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'document.access_logged']);
    }

    public function test_abac_foreign_document_denied(): void
    {
        // Creation par un acteur national (institution_id null dans
        // les claims) pour simuler un document d'une autre institution.
        $response = $this->post('/api/v1/documents', [
            'title' => 'Doc etranger',
            'category_id' => $this->category()->id,
            'institution_id' => $this->otherInstitutionId,
            'file' => $this->pdfFile(),
        ], $this->auth(['institution_id' => null]) + ['Idempotency-Key' => 'doc-'.Str::random(12)]);
        $response->assertCreated();
        $foreign = Document::findOrFail($response->json('data.id'));

        $this->getJson('/api/v1/documents/'.$foreign->id, $this->auth())->assertForbidden();
    }

    public function test_national_document_visible_to_all(): void
    {
        $national = $this->createDocument([
            'title' => 'Circulaire nationale',
            'institution_id' => null,
        ]);

        $this->getJson('/api/v1/documents/'.$national->id, $this->auth())->assertOk();
    }

    public function test_idempotent_replay(): void
    {
        $headers = $this->auth() + ['Idempotency-Key' => 'idem-'.Str::random(12)];
        $payload = [
            'title' => 'PV de reception materiel',
            'category_id' => $this->category('administratif')->id,
        ];

        $first = $this->postJson('/api/v1/documents', $payload, $headers)->assertCreated();
        $second = $this->postJson('/api/v1/documents', $payload, $headers);

        $second->assertCreated()->assertHeader('Idempotent-Replay', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Document::count());
    }

    public function test_unsupported_mime_rejected(): void
    {
        $exe = UploadedFile::fake()->createWithContent('virus.exe', "MZ\x90\x00fake-binary");

        $this->post('/api/v1/documents', [
            'title' => 'Fichier executable',
            'category_id' => $this->category()->id,
            'file' => $exe,
        ], $this->auth() + ['Idempotency-Key' => 'doc-'.Str::random(12)])
            ->assertStatus(415);
    }

    public function test_access_log_endpoint(): void
    {
        $document = $this->createDocument([], $this->pdfFile());
        $this->get('/api/v1/documents/'.$document->id.'/download', $this->auth())->assertOk();
        $this->getJson('/api/v1/documents/'.$document->id, $this->auth())->assertOk();

        $logs = $this->getJson('/api/v1/documents/'.$document->id.'/access-log', $this->auth())
            ->assertOk()->json('data');

        $actions = collect($logs)->pluck('action');
        $this->assertContains('download', $actions);
        $this->assertContains('view', $actions);
    }
}
