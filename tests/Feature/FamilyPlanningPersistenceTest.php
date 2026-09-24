<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoFamilyPlanning;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-13 Phase 2 — Family Planning visit database persistence.
 */
class FamilyPlanningPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedResident(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-940',
            'zone' => 'Zone 1',
            'street' => 'FP St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-940',
            'first_name' => $overrides['first_name'] ?? 'Ana',
            'last_name' => $overrides['last_name'] ?? 'Family',
            'middle_name' => null,
            'relation' => 'Daughter',
            'sex' => 'Female',
            'birthday' => $overrides['birthday'] ?? '1990-05-01',
            'relationship_status' => 'Married',
            'fp_user' => $overrides['fp_user'] ?? 'Yes',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'visited_at' => '2026-08-20',
            'remarks' => 'Follow-up commodities provided.',
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 10],
                ['name' => 'Condoms', 'quantity' => 3],
            ],
        ], $overrides);
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.family-planning.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function updateRoute(Household $household, Resident $resident, string $visitNo): string
    {
        return route('household-profiling.members.family-planning.update', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => $visitNo,
        ]);
    }

    public function test_family_planning_visits_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('family_planning_visits'));
        foreach ([
            'resident_id', 'visit_no', 'visited_at', 'remarks', 'commodities',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('family_planning_visits', $column), $column);
        }
    }

    public function test_store_creates_visit_with_route_resolved_resident_id(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())
            ->assertRedirect(route('household-profiling.members.family-planning.index', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));

        $this->assertSame(1, FamilyPlanningVisit::query()->count());
        $row = FamilyPlanningVisit::query()->first();
        $this->assertNotNull($row);
        $this->assertSame($resident->id, $row->resident_id);
        $this->assertSame('FP-001', $row->visit_no);
        $this->assertSame('2026-08-20', $row->visited_at->toDateString());
        $this->assertSame('Follow-up commodities provided.', $row->remarks);
        $this->assertSame([
            ['name' => 'Pills', 'quantity' => 10],
            ['name' => 'Condoms', 'quantity' => 3],
        ], $row->commodities);
    }

    public function test_browser_supplied_resident_id_cannot_change_ownership(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-941',
            'member_no' => 'MB-941',
        ]);
        ['resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-942',
            'member_no' => 'MB-942',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $h1->household_no,
            'memberId' => $r1->member_no,
        ]))->post($this->storeRoute($h1, $r1), $this->validStorePayload([
            'resident_id' => $r2->id,
        ]))->assertSessionHasErrors('resident_id');

        $this->assertSame(0, FamilyPlanningVisit::query()->count());
    }

    public function test_history_lists_persisted_visits_for_correct_resident_only(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-943',
            'member_no' => 'MB-943',
            'first_name' => 'Owner',
            'last_name' => 'One',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-944',
            'member_no' => 'MB-944',
            'first_name' => 'Other',
            'last_name' => 'Two',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload([
            'remarks' => 'Owner visit only',
            'visited_at' => '2026-08-20',
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 10],
                ['name' => 'Condoms', 'quantity' => 3],
            ],
        ]))->assertRedirect();

        $this->assertDatabaseHas('family_planning_visits', [
            'resident_id' => $r1->id,
            'remarks' => 'Owner visit only',
        ]);

        $htmlOwner = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $h1->household_no,
            'memberId' => $r1->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('08/20/2026', $htmlOwner);
        $this->assertStringContainsString('Pills, Condoms', $htmlOwner);
        $this->assertStringContainsString('FP-001', $htmlOwner);
        $this->assertSame(1, substr_count($htmlOwner, 'data-fp-row'));

        $htmlOther = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No family planning visits recorded for this resident.', $htmlOther);
        $this->assertStringNotContainsString('Owner visit only', $htmlOther);
        $this->assertSame(0, FamilyPlanningVisit::query()->where('resident_id', $r2->id)->count());
    }

    public function test_show_loads_correct_db_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();

        $html = $this->get(route('household-profiling.members.family-planning.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => 'FP-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-visit-id="FP-001"', $html);
        $this->assertStringContainsString('VIEW FAMILY PLANNING RECORD', $html);
        $this->assertStringContainsString('Follow-up commodities provided.', $html);
        $this->assertStringContainsString('Pills', $html);
        $this->assertStringContainsString('Condoms', $html);
    }

    public function test_cross_resident_show_returns_404(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-945',
            'member_no' => 'MB-945',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-946',
            'member_no' => 'MB-946',
            'first_name' => 'Other',
            'last_name' => 'Member',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload())->assertRedirect();

        $this->get(route('household-profiling.members.family-planning.show', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
            'visitId' => 'FP-001',
        ]))->assertNotFound();
    }

    public function test_edit_loads_correct_db_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'visited_at' => '2026-07-01',
            'remarks' => 'Edit preload remarks',
        ]))->assertRedirect();

        $html = $this->get(route('household-profiling.members.family-planning.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => 'FP-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-fp-mode="edit"', $html);
        $this->assertStringContainsString('value="2026-07-01"', $html);
        $this->assertStringContainsString('Edit preload remarks', $html);
        $this->assertStringContainsString(
            'action="'.e($this->updateRoute($household, $resident, 'FP-001')).'"',
            $html
        );
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
    }

    public function test_cross_resident_edit_returns_404(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-947',
            'member_no' => 'MB-947',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-948',
            'member_no' => 'MB-948',
            'first_name' => 'Other',
            'last_name' => 'Member',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload())->assertRedirect();

        $this->get(route('household-profiling.members.family-planning.edit', [
            'householdNo' => $h2->household_no,
            'memberId' => $r2->member_no,
            'visitId' => 'FP-001',
        ]))->assertNotFound();
    }

    public function test_update_modifies_same_row_and_cannot_change_ownership(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-949',
            'member_no' => 'MB-949',
        ]);
        ['resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-950',
            'member_no' => 'MB-950',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload())->assertRedirect();

        $this->put($this->updateRoute($h1, $r1, 'FP-001'), $this->validStorePayload([
            'visited_at' => '2026-08-21',
            'remarks' => 'Updated remarks',
            'commodities' => [
                ['name' => 'DMPA', 'quantity' => 1],
            ],
            'resident_id' => $r2->id,
        ]))->assertSessionHasErrors('resident_id');

        $this->put($this->updateRoute($h1, $r1, 'FP-001'), $this->validStorePayload([
            'visited_at' => '2026-08-21',
            'remarks' => 'Updated remarks',
            'commodities' => [
                ['name' => 'DMPA', 'quantity' => 1],
            ],
        ]))->assertRedirect(route('household-profiling.members.family-planning.show', [
            'householdNo' => $h1->household_no,
            'memberId' => $r1->member_no,
            'visitId' => 'FP-001',
        ]));

        $this->assertSame(1, FamilyPlanningVisit::query()->count());
        $row = FamilyPlanningVisit::query()->first();
        $this->assertSame($r1->id, $row->resident_id);
        $this->assertSame('FP-001', $row->visit_no);
        $this->assertSame('2026-08-21', $row->visited_at->toDateString());
        $this->assertSame('Updated remarks', $row->remarks);
        $this->assertSame([['name' => 'DMPA', 'quantity' => 1]], $row->commodities);
    }

    public function test_cross_resident_update_is_denied(): void
    {
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedResident([
            'household_no' => 'HH-951',
            'member_no' => 'MB-951',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedResident([
            'household_no' => 'HH-952',
            'member_no' => 'MB-952',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);

        $this->post($this->storeRoute($h1, $r1), $this->validStorePayload([
            'remarks' => 'Original',
        ]))->assertRedirect();

        $this->put($this->updateRoute($h2, $r2, 'FP-001'), $this->validStorePayload([
            'remarks' => 'Hijacked',
        ]))->assertForbidden();

        $this->assertSame('Original', FamilyPlanningVisit::query()->where('resident_id', $r1->id)->value('remarks'));
    }

    public function test_visited_at_is_required(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'visited_at' => '',
        ]))->assertSessionHasErrors('visited_at');

        $this->assertSame(0, FamilyPlanningVisit::query()->count());
    }

    public function test_commodity_allow_list_validation(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'commodities' => [
                ['name' => 'NotARealCommodity', 'quantity' => 1],
            ],
        ]))->assertSessionHasErrors('commodities.0.name');

        $this->assertSame(0, FamilyPlanningVisit::query()->count());
    }

    public function test_quantity_must_be_integer_min_zero(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'commodities' => [
                ['name' => 'Pills', 'quantity' => -1],
            ],
        ]))->assertSessionHasErrors('commodities.0.quantity');

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 'abc'],
            ],
        ]))->assertSessionHasErrors('commodities.0.quantity');

        $this->assertSame(0, FamilyPlanningVisit::query()->count());
    }

    public function test_store_route_is_post_and_protected_by_ui_role_middleware(): void
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('household-profiling.members.family-planning.store');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertContains('ui.role', $route->gatherMiddleware());

        $update = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('household-profiling.members.family-planning.update');
        $this->assertNotNull($update);
        $this->assertContains('PUT', $update->methods());
        $this->assertContains('ui.role', $update->gatherMiddleware());
    }

    public function test_create_form_posts_to_store_for_persisted_resident(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $html = $this->get(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString(
            'action="'.e($this->storeRoute($household, $resident)).'"',
            $html
        );
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('data-demo="false"', $html);
    }

    public function test_persisted_resident_history_does_not_inject_demo_fixture_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'household_no' => 'HH-151',
            'member_no' => 'MB-001',
            'first_name' => 'Kristine',
            'last_name' => 'Reyes',
        ]);

        $html = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No family planning visits recorded for this resident.', $html);
        $this->assertStringNotContainsString('06/08/2026', $html);
        $this->assertNotEmpty(DemoFamilyPlanning::forMember('HH-151', 'MB-002'));
    }

    public function test_demo_only_member_store_fails_closed(): void
    {
        $this->post(route('household-profiling.members.family-planning.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validStorePayload())->assertNotFound();

        $this->assertSame(0, FamilyPlanningVisit::query()->count());
    }

    public function test_visit_no_is_server_generated_and_unique(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident();

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();
        $this->post($this->storeRoute($household, $resident), $this->validStorePayload([
            'visited_at' => '2026-08-22',
        ]))->assertRedirect();

        $numbers = FamilyPlanningVisit::query()->orderBy('id')->pluck('visit_no')->all();
        $this->assertSame(['FP-001', 'FP-002'], $numbers);
    }

    public function test_fp_user_demographic_is_unchanged_by_visit_store(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedResident([
            'fp_user' => 'No',
        ]);

        $this->post($this->storeRoute($household, $resident), $this->validStorePayload())->assertRedirect();

        $this->assertSame('No', $resident->fresh()->fp_user);
        $this->assertSame(1, FamilyPlanningVisit::query()->count());
    }
}
