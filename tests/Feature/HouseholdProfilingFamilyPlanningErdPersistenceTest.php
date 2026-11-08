<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DewormingRecord;
use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Support\FamilyPlanningErdMode;
use App\Support\FamilyPlanningVisitService;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\AssertsAtRestStoredField;
use Tests\TestCase;

class HouseholdProfilingFamilyPlanningErdPersistenceTest extends TestCase
{
    use AssertsAtRestStoredField;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    private function provisionErdFamilyPlanningTable(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::dropIfExists('family_planning');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        FamilyPlanningErdMode::resetCachedState();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedMember(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-920',
            'zone' => 'Zone 1',
            'street' => 'FP ERD St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-920',
            'first_name' => $overrides['first_name'] ?? 'Elena',
            'last_name' => $overrides['last_name'] ?? 'Santos',
            'sex' => 'Female',
            'birthday' => '1990-03-15',
        ]);

        return compact('household', 'resident');
    }

    private function storeRoute(Household $household, Resident $resident): string
    {
        return route('household-profiling.members.family-planning.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);
    }

    private function updateRoute(Household $household, Resident $resident, string $visitId): string
    {
        return route('household-profiling.members.family-planning.update', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => $visitId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'visited_at' => '2026-03-15',
            'remarks' => 'ERD visit remarks',
        ], $overrides);
    }

    public function test_erd_create_inserts_one_family_planning_row(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post($this->storeRoute($household, $resident), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, DB::table('family_planning')->count());
    }

    public function test_erd_create_persists_resident_id(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload())->assertRedirect();

        $this->assertDatabaseHas('family_planning', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_erd_create_persists_visitation_date(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => '2026-04-02',
        ]))->assertRedirect();

        $this->assertDatabaseHas('family_planning', [
            'resident_id' => $resident->id,
            'visitation_date' => '2026-04-02',
        ]);
    }

    public function test_future_visited_at_is_rejected(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => now()->addDay()->toDateString(),
        ]))->assertSessionHasErrors('visited_at');

        $this->assertSame(0, DB::table('family_planning')->count());
    }

    public function test_erd_create_persists_remarks(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'remarks' => 'Counseling provided',
        ]))->assertRedirect();

        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Counseling provided', [
            'resident_id' => $resident->id,
        ]);
    }

    public function test_erd_create_resolves_fp_id_to_fp_presentation_contract(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['resident' => $resident] = $this->seedPersistedMember();

        app(FamilyPlanningVisitService::class)->createForResident($resident, $this->validPayload());

        $fpId = (int) DB::table('family_planning')->value('fp_id');
        $presentation = app(FamilyPlanningVisitService::class)
            ->findPresentationForResident($resident, sprintf('FP-%03d', $fpId));

        $this->assertNotNull($presentation);
        $this->assertSame(sprintf('FP-%03d', $fpId), $presentation['id']);
        $this->assertSame('2026-03-15', $presentation['visited_at']);
        $this->assertSame('ERD visit remarks', $presentation['remarks']);
    }

    public function test_erd_second_visit_creates_second_row_not_overwrite(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => '2026-03-01',
            'remarks' => 'First visit',
        ]))->assertRedirect();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => '2026-03-20',
            'remarks' => 'Second visit',
        ]))->assertRedirect();

        $this->assertSame(2, DB::table('family_planning')->where('resident_id', $resident->id)->count());
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'First visit', [
            'visitation_date' => '2026-03-01',
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Second visit', [
            'visitation_date' => '2026-03-20',
        ]);
    }

    public function test_erd_update_modifies_only_requested_fp_id(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        app(FamilyPlanningVisitService::class)->createForResident($resident, $this->validPayload([
            'visited_at' => '2026-03-01',
            'remarks' => 'Visit one',
        ]));
        app(FamilyPlanningVisitService::class)->createForResident($resident, $this->validPayload([
            'visited_at' => '2026-03-10',
            'remarks' => 'Visit two',
        ]));

        $this->put($this->updateRoute($household, $resident, 'FP-001'), $this->validPayload([
            'visited_at' => '2026-03-05',
            'remarks' => 'Updated visit one',
        ]))->assertRedirect();

        $this->assertDatabaseHas('family_planning', [
            'fp_id' => 1,
            'visitation_date' => '2026-03-05',
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Updated visit one', [
            'fp_id' => 1,
        ]);
        $this->assertDatabaseHas('family_planning', [
            'fp_id' => 2,
            'visitation_date' => '2026-03-10',
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Visit two', [
            'fp_id' => 2,
        ]);
    }

    public function test_erd_cross_resident_update_is_rejected(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $h1, 'resident' => $r1] = $this->seedPersistedMember([
            'household_no' => 'HH-921',
            'member_no' => 'MB-921',
        ]);
        ['household' => $h2, 'resident' => $r2] = $this->seedPersistedMember([
            'household_no' => 'HH-922',
            'member_no' => 'MB-922',
            'first_name' => 'Other',
            'last_name' => 'Resident',
        ]);

        app(FamilyPlanningVisitService::class)->createForResident($r1, $this->validPayload([
            'remarks' => 'Owner record',
        ]));

        $this->put($this->updateRoute($h2, $r2, 'FP-001'), $this->validPayload([
            'remarks' => 'Hijacked',
        ]))->assertForbidden();

        $this->assertDatabaseHas('family_planning', [
            'fp_id' => 1,
        ]);
        $this->assertPlaintextStoredField('family_planning', 'remarks', 'Owner record', [
            'fp_id' => 1,
        ]);
    }

    public function test_erd_get_history_sees_newly_created_record(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => '2026-05-10',
            'remarks' => 'History visible',
        ]))->assertRedirect();

        $html = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('05/10/2026', $html);
        $this->assertSame(1, substr_count($html, 'data-fp-row'));
    }

    public function test_erd_get_detail_sees_newly_created_record(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'visited_at' => '2026-06-01',
            'remarks' => 'Detail visible',
        ]))->assertRedirect();

        $this->get(route('household-profiling.members.family-planning.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => 'FP-001',
        ]))
            ->assertOk()
            ->assertSee('Detail visible', false)
            ->assertSee('data-visit-id="FP-001"', false);
    }

    public function test_legacy_sqlite_family_planning_visits_behavior_remains_functional(): void
    {
        Schema::dropIfExists('family_planning');
        FamilyPlanningErdMode::resetCachedState();

        $this->assertFalse(FamilyPlanningErdMode::isActive());
        $this->assertTrue(Schema::hasTable('family_planning_visits'));

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'household_no' => 'HH-923',
            'member_no' => 'MB-923',
        ]);

        $this->post($this->storeRoute($household, $resident), array_merge($this->validPayload(), [
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 5],
            ],
        ]))->assertRedirect();

        $this->assertSame(1, FamilyPlanningVisit::query()->count());
        $row = FamilyPlanningVisit::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('FP-001', $row->visit_no);
        $this->assertSame([['name' => 'Pills', 'quantity' => 5]], $row->commodities);
    }

    private function provisionErdCommoditiesTable(): void
    {
        Schema::dropIfExists('fp_commodities_given');
        Schema::create('fp_commodities_given', function ($table): void {
            $table->id('commodity_given_id');
            $table->unsignedBigInteger('fp_id');
            $table->string('commodity_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
        FamilyPlanningErdMode::resetCachedState();
    }

    public function test_erd_commodities_persist_when_fp_commodities_given_exists(): void
    {
        $this->provisionErdFamilyPlanningTable();
        $this->provisionErdCommoditiesTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->post($this->storeRoute($household, $resident), $this->validPayload([
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 10],
            ],
        ]))->assertRedirect();

        $fpId = (int) DB::table('family_planning')->where('resident_id', $resident->id)->value('fp_id');
        $this->assertDatabaseHas('fp_commodities_given', [
            'fp_id' => $fpId,
            'commodity_name' => 'Pills',
            'quantity' => 10,
        ]);

        $html = $this->get(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('name="commodities[0][name]"', $html);
        $this->assertStringContainsString('data-fp-commodity-add', $html);
    }

    public function test_erd_commodities_are_not_falsely_reported_as_persisted(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post($this->storeRoute($household, $resident), $this->validPayload([
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 10],
            ],
        ]))->assertSessionHasErrors('commodities');

        $this->assertSame(0, DB::table('family_planning')->count());
    }

    public function test_erd_create_form_does_not_expose_editable_commodity_inputs(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        $html = $this->get(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="commodities[0][name]"', $html);
        $this->assertStringNotContainsString('data-fp-commodity-add', $html);
        $this->assertStringContainsString(FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE, $html);
        $this->assertStringContainsString('name="visited_at"', $html);
        $this->assertStringContainsString('name="remarks"', $html);
    }

    public function test_erd_edit_form_does_not_expose_editable_commodity_inputs(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        app(FamilyPlanningVisitService::class)->createForResident($resident, $this->validPayload([
            'remarks' => 'Editable ERD visit',
        ]));

        $html = $this->get(route('household-profiling.members.family-planning.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => 'FP-001',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="commodities[0][name]"', $html);
        $this->assertStringNotContainsString('data-fp-commodity-add', $html);
        $this->assertStringContainsString(FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE, $html);
        $this->assertStringContainsString('name="visited_at"', $html);
        $this->assertStringContainsString('Editable ERD visit', $html);
    }

    public function test_legacy_form_still_exposes_commodity_controls(): void
    {
        Schema::dropIfExists('family_planning');
        FamilyPlanningErdMode::resetCachedState();

        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember([
            'household_no' => 'HH-924',
            'member_no' => 'MB-924',
        ]);

        $html = $this->get(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-fp-commodity-add', $html);
        $this->assertStringContainsString('name="commodities[0][name]"', $html);
        $this->assertStringNotContainsString(FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE, $html);
    }

    public function test_erd_history_and_detail_do_not_imply_commodity_storage(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedMember();

        app(FamilyPlanningVisitService::class)->createForResident($resident, $this->validPayload([
            'remarks' => 'Truthful ERD detail',
        ]));

        $history = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('Commodities Given', $history);
        $this->assertStringContainsString('03/15/2026', $history);

        $detail = $this->get(route('household-profiling.members.family-planning.show', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
            'visitId' => 'FP-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Truthful ERD detail', $detail);
        $this->assertStringContainsString(FamilyPlanningErdMode::COMMODITIES_UI_MESSAGE, $detail);
        $this->assertStringNotContainsString('Commodities Given', $detail);
    }

    public function test_family_planning_guard_allows_compatible_erd_schema(): void
    {
        $this->provisionErdFamilyPlanningTable();
        $this->assertTrue(FamilyPlanningErdMode::isActive());
        $this->assertFalse(HouseholdProfilingWriteGuard::isFamilyPlanningWriteUnsupported());

        HouseholdProfilingWriteGuard::rejectFamilyPlanningWrite();
        $this->addToAssertionCount(1);
    }

    public function test_other_phase_one_write_guards_remain_active(): void
    {
        $this->provisionErdFamilyPlanningTable();
        Schema::dropIfExists('risk_assessments');
        Schema::create('risk_assessment', function ($table): void {
            $table->id('risk_assessment_id');
            $table->unsignedBigInteger('resident_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(HouseholdProfilingWriteGuard::MESSAGE);

        HouseholdProfilingWriteGuard::rejectRiskAssessmentWrite();
    }

    public function test_deworming_remains_unaffected_by_family_planning_erd_writes(): void
    {
        $this->provisionErdFamilyPlanningTable();
        ['resident' => $resident] = $this->seedPersistedMember();

        $record = app(\App\Support\DewormingRecordService::class)->createForResident($resident, [
            'year' => (int) now()->year,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => now()->toDateString(),
        ]);

        $this->assertInstanceOf(DewormingRecord::class, $record);
        $this->assertSame(1, DewormingRecord::query()->count());
    }

    public function test_incompatible_family_planning_schema_still_rejects_writes(): void
    {
        $this->provisionErdFamilyPlanningTable();
        Schema::dropIfExists('family_planning');
        FamilyPlanningErdMode::resetCachedState();

        $this->assertTrue(HouseholdProfilingWriteGuard::isFamilyPlanningWriteUnsupported());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(HouseholdProfilingWriteGuard::MESSAGE);

        HouseholdProfilingWriteGuard::rejectFamilyPlanningWrite();
    }
}
