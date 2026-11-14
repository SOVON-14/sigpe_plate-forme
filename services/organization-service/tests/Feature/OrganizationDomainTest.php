<?php

namespace Tests\Feature;

use App\Enums\InstitutionStatus;
use App\Enums\UnitStatus;
use App\Exceptions\DomainException;
use App\Models\Institution;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationalUnitType;
use App\Models\OrganizationHistory;
use App\Models\OutboxMessage;
use App\Models\Position;
use App\Services\InstitutionService;
use App\Services\OrganizationalUnitService;
use App\Services\PositionService;
use Database\Seeders\UnitTypesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationDomainTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private OrganizationalUnitType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnitTypesTableSeeder::class);
        $this->type = OrganizationalUnitType::whereNull('institution_id')->where('code', 'direction')->firstOrFail();
        $this->institution = app(InstitutionService::class)->create(
            'MINISTERE-TEST', 'Ministère de Test', 'ministere', null, (string) Str::uuid7(), 'corr-1',
        );
    }

    public function test_institution_creation_records_history_and_outbox(): void
    {
        $this->assertSame('active', $this->institution->status->value);
        $this->assertDatabaseHas('organization_histories', ['entity_type' => 'institution', 'change_type' => 'created']);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'organization.institution.created', 'status' => 'pending']);
    }

    public function test_institution_status_change_emits_event(): void
    {
        app(InstitutionService::class)->changeStatus($this->institution, InstitutionStatus::Suspended, (string) Str::uuid7(), 'maintenance', 'corr-2');

        $this->assertSame('suspended', $this->institution->fresh()->status->value);
        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'organization.institution.status_changed']);
    }

    public function test_unit_tree_creation_and_cycle_prevention(): void
    {
        $service = app(OrganizationalUnitService::class);
        $actor = (string) Str::uuid7();

        $direction = $service->create($this->institution, $this->type, 'DIR-1', 'Direction des Tests', null, $actor);
        $serviceType = OrganizationalUnitType::where('code', 'service')->firstOrFail();
        $serviceUnit = $service->create($this->institution, $serviceType, 'SRV-1', 'Service A', $direction, $actor);

        // Déplacer SRV-1 sous lui-même → cycle refusé
        try {
            $service->move($direction, $serviceUnit->load('parent'), $actor);
            $this->fail('Le cycle aurait dû être refusé.');
        } catch (DomainException $e) {
            $this->assertSame('UNIT_MOVE_CYCLE', $e->errorCode);
        }

        // Auto-rattachement direct refusé
        $this->expectException(DomainException::class);
        $service->move($direction, $direction, $actor);
    }

    public function test_unit_cannot_attach_to_foreign_institution(): void
    {
        $other = app(InstitutionService::class)->create('MIN-2', 'Ministère 2', 'ministere', null, (string) Str::uuid7());
        $foreignType = OrganizationalUnitType::create([
            'institution_id' => $other->id, 'code' => 'local', 'label' => 'Type local',
        ]);

        $this->expectException(DomainException::class);
        app(OrganizationalUnitService::class)->create(
            $this->institution, $foreignType, 'X-1', 'Unité', null, (string) Str::uuid7(),
        );
    }

    public function test_position_assignment_and_unit_head(): void
    {
        $unitService = app(OrganizationalUnitService::class);
        $positionService = app(PositionService::class);
        $actor = (string) Str::uuid7();

        $unit = $unitService->create($this->institution, $this->type, 'DIR-9', 'Direction 9', null, $actor);
        $position = $positionService->create($unit, 'CHEF', 'Chef de direction', $actor);

        $accountId = (string) Str::uuid7();
        $positionService->assignResponsible($position, $accountId, $actor);
        $this->assertTrue($position->fresh()->isFilled());

        $positionService->setUnitHead($unit, $position, $actor);
        $this->assertSame($position->id, $unit->fresh()->head_position_id);

        $this->assertDatabaseHas('outbox_messages', ['event_type' => 'organization.position.responsible_changed']);
    }

    public function test_history_tracks_reorganization(): void
    {
        $service = app(OrganizationalUnitService::class);
        $actor = (string) Str::uuid7();
        $type = OrganizationalUnitType::where('code', 'service')->firstOrFail();

        $parent = $service->create($this->institution, $this->type, 'P-1', 'Parent', null, $actor);
        $child = $service->create($this->institution, $type, 'C-1', 'Enfant', $parent, $actor);

        $service->move($child, null, $actor, 'Restructuration');

        $move = OrganizationHistory::where('entity_id', $child->id)->where('change_type', 'moved')->firstOrFail();
        $this->assertSame($parent->id, $move->old_values['parent_id']);
        $this->assertNull($move->new_values['parent_id']);
        $this->assertSame('Restructuration', $move->reason);
    }

    public function test_uuidv7_identifiers(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $this->institution->id,
        );
    }
}
