<?php

namespace Tests\Feature;

use App\Enums\AdministrativeStatus;
use App\Enums\EntryMode;
use App\Enums\PhysicalState;
use App\Exceptions\DomainException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetHistory;
use App\Models\AssetSubcategory;
use App\Models\OutboxMessage;
use App\Services\AssetService;
use Database\Seeders\AssetCategoriesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssetDomainTest extends TestCase
{
    use RefreshDatabase;

    private AssetService $service;
    private AssetCategory $category;
    private string $institutionId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AssetCategoriesTableSeeder::class);
        $this->service = app(AssetService::class);
        $this->category = AssetCategory::where('code', 'materiels_roulants')->firstOrFail();
        $this->institutionId = (string) Str::uuid7();
        $this->actorId = (string) Str::uuid7();
    }

    private function register(array $overrides = []): Asset
    {
        return $this->service->register(
            institutionId: $overrides['institution_id'] ?? $this->institutionId,
            organizationalUnitId: $overrides['organizational_unit_id'] ?? null,
            category: $overrides['category'] ?? $this->category,
            subcategory: $overrides['subcategory'] ?? null,
            designation: $overrides['designation'] ?? 'Véhicule de service',
            description: $overrides['description'] ?? null,
            entryDate: $overrides['entry_date'] ?? '2026-01-15',
            entryMode: $overrides['entry_mode'] ?? EntryMode::Achat,
            entryReference: $overrides['entry_reference'] ?? null,
            physicalState: $overrides['physical_state'] ?? PhysicalState::Neuf,
            location: $overrides['location'] ?? 'Parking ministériel',
            technicalAttributes: $overrides['technical_attributes'] ?? ['immatriculation' => 'T-1234'],
            valueUnknown: $overrides['value_unknown'] ?? false,
            valueUnknownJustification: $overrides['value_unknown_justification'] ?? null,
            registeredBy: $this->actorId,
            correlationId: 'corr-test',
        );
    }

    public function test_register_creates_asset_with_unique_code_and_events(): void
    {
        $asset = $this->register();

        $this->assertMatchesRegularExpression('/^PAT-\d{4}-\d{6}$/', $asset->patrimonial_code);
        $this->assertSame('actif', $asset->administrative_status->value);
        $this->assertNotNull($asset->qr_token);

        $this->assertDatabaseHas('asset_histories', ['asset_id' => $asset->id, 'change_type' => 'registered']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'asset.registered', 'status' => 'pending']);
    }

    public function test_patrimonial_code_unique_per_institution_but_asset_id_immutable(): void
    {
        $a1 = $this->register();
        $a2 = $this->register();

        $this->assertNotSame($a1->patrimonial_code, $a2->patrimonial_code);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $a1->id);
    }

    public function test_value_unknown_requires_justification(): void
    {
        $this->expectException(DomainException::class);
        $this->register(['value_unknown' => true, 'value_unknown_justification' => null]);
    }

    public function test_subcategory_must_match_category_and_institution(): void
    {
        $otherInstitution = (string) Str::uuid7();
        $subcat = AssetSubcategory::create([
            'category_id' => $this->category->id,
            'institution_id' => $otherInstitution,
            'code' => 'camions',
            'label' => 'Camions',
        ]);

        try {
            $this->register(['subcategory' => $subcat]);
            $this->fail('Sous-catégorie étrangère acceptée.');
        } catch (DomainException $e) {
            $this->assertSame('ASSET_SUBCATEGORY_FOREIGN', $e->errorCode);
        }
    }

    public function test_exit_requires_reform_status_first(): void
    {
        $asset = $this->register();

        try {
            $this->service->changeAdministrativeStatus($asset, AdministrativeStatus::Sorti, $this->actorId);
            $this->fail('Sortie directe acceptée.');
        } catch (DomainException $e) {
            $this->assertSame('ASSET_EXIT_REQUIRES_REFORM', $e->errorCode);
        }

        $this->service->changeAdministrativeStatus($asset, AdministrativeStatus::EnReforme, $this->actorId, 'Fin de vie');
        $asset = $this->service->changeAdministrativeStatus($asset, AdministrativeStatus::Sorti, $this->actorId, 'Décision autorité');
        $this->assertSame('sorti', $asset->administrative_status->value);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'asset.status_changed']);
    }

    public function test_interministerial_rattachement_keeps_asset_id_and_old_code(): void
    {
        $asset = $this->register();
        $newInstitution = (string) Str::uuid7();
        $assetId = $asset->id;
        $oldCode = $asset->patrimonial_code;

        $moved = $this->service->confirmRattachement($asset, $newInstitution, null, 'movement-service');

        $this->assertSame($assetId, $moved->id); // asset_id immuable
        $this->assertSame($newInstitution, $moved->institution_id);
        $this->assertNotSame($oldCode, $moved->patrimonial_code); // nouveau code ministériel
        $this->assertDatabaseHas('asset_histories', ['asset_id' => $assetId, 'change_type' => 'rattachement_confirmed']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'asset.rattachement_confirmed']);
    }

    public function test_physical_state_change_recorded(): void
    {
        $asset = $this->register();
        $asset = $this->service->changePhysicalState($asset, PhysicalState::Mauvais, $this->actorId, 'Constat');

        $this->assertSame('mauvais', $asset->physical_state->value);
        $this->assertDatabaseHas('asset_histories', ['asset_id' => $asset->id, 'change_type' => 'physical_state_changed']);
    }
}
