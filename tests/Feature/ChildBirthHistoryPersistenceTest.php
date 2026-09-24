<?php

namespace Tests\Feature;

use App\Models\ChildBirthHistory;
use App\Models\Household;
use App\Models\Resident;
use App\Support\ChildBirthHistoryService;
use App\Support\HouseholdProfilingPresenter;
use App\Support\StaffRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-06 Phase 2 — Shared Child Care birth history persistence foundation.
 */
class ChildBirthHistoryPersistenceTest extends TestCase
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
    private function seedPersistedChild(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-820',
            'zone' => 'Zone 1',
            'street' => 'Test St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-820',
            'first_name' => 'Baby',
            'last_name' => 'Persist',
            'relation' => 'Son',
            'birthday' => now()->subMonths(6)->format('Y-m-d'),
            'sex' => 'Male',
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'birth_weight' => '3.10',
            'birth_length' => '49.00',
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
            'breastfeeding_date' => '2024-06-01',
        ], $overrides);
    }

    public function test_child_birth_histories_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('child_birth_histories'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'birth_weight_kg'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'birth_length_cm'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'status'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'pcab'));
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'breastfeeding_date'));
    }

    public function test_persisted_resident_can_create_birth_history(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-immunization', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));

        $this->assertDatabaseHas('child_birth_histories', [
            'resident_id' => $resident->id,
            'birth_weight_kg' => '3.10',
            'birth_length_cm' => '49.00',
            'status' => 'Normal',
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
        ]);
    }

    public function test_saved_record_uses_correct_residents_id(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload());

        $record = ChildBirthHistory::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame($resident->id, $record->resident_id);
    }

    public function test_resident_id_cannot_be_spoofed_through_request_payload(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-821',
        ]);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), array_merge($this->validPayload(), [
            'resident_id' => $other->id,
        ]))->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('child_birth_histories', 0);
    }

    public function test_one_resident_cannot_receive_duplicate_birth_history_rows(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '2.80',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ChildBirthHistory::query()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '3.50',
        ]);
    }

    public function test_existing_birth_history_record_updates_instead_of_creating_second_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '2.80',
            'birth_length_cm' => '45.00',
            'status' => 'Low Birth Weight',
        ]);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload(['birth_weight' => '3.40']));

        $this->assertSame(1, ChildBirthHistory::query()->where('resident_id', $resident->id)->count());
        $this->assertDatabaseHas('child_birth_histories', [
            'resident_id' => $resident->id,
            'birth_weight_kg' => '3.40',
            'status' => 'Normal',
        ]);
    }

    public function test_demo_only_member_cannot_create_db_birth_history_row(): void
    {
        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('child_birth_histories', 0);
    }

    public function test_db_backed_record_can_be_read_back_into_presentation_shape(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '3.10',
            'birth_length_cm' => '49.00',
            'status' => 'Normal',
            'pcab' => ChildBirthHistoryService::PCAB_AT_LEAST_2_DOSES,
            'breastfeeding_date' => '2024-06-01',
        ]);

        $resident->load('childBirthHistory');
        $member = HouseholdProfilingPresenter::memberFromModel($resident);

        $this->assertArrayHasKey('birth_history', $member);
        $this->assertSame('3.10', $member['birth_history']['weight']);
        $this->assertSame('49.00', $member['birth_history']['length']);
        $this->assertSame('Normal', $member['birth_history']['status']);
        $this->assertSame(
            ChildBirthHistoryService::pcabDisplayLabel($record->pcab),
            $member['birth_history']['pcab']
        );
        $this->assertSame('2024-06-01', $member['birth_history']['breastfeeding_date']);
    }

    public function test_db_backed_birth_history_renders_on_child_immunization_page(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'birth_weight_kg' => '3.10',
            'birth_length_cm' => '49.00',
            'status' => 'Normal',
        ]);

        $html = $this->get(route('household-profiling.members.child-immunization', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('3.10', $html);
        $this->assertStringContainsString('49.00', $html);
        $this->assertStringContainsString('Normal', $html);
    }

    public function test_db_backed_birth_history_edit_page_uses_db_persistence_mode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $html = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString('method="post"', strtolower($html));
        $this->assertStringContainsString(
            route('household-profiling.members.child-immunization.birth-history.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]),
            $html
        );
    }

    public function test_unknown_member_birth_history_edit_is_not_found(): void
    {
        $html = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('No record found.', $html);
        $this->assertStringNotContainsString('demo household', $html);
    }

    public function test_birth_history_store_route_exists(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.child-immunization.birth-history.store'));
    }

    public function test_resident_has_one_child_birth_history_relationship(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        $record = ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $resident->refresh();
        $this->assertTrue($resident->childBirthHistory->is($record));
    }

    public function test_cross_household_birth_history_store_fails_and_creates_no_row(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-830']);
        $householdB = Household::factory()->create(['household_no' => 'HH-831']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-830',
        ]);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('child_birth_histories', 0);
    }

    public function test_physical_resident_deletion_is_restricted_when_child_birth_history_exists(): void
    {
        ['resident' => $resident] = $this->seedPersistedChild();

        ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        $resident->forceDelete();
    }

    public function test_invalid_pcab_value_uses_cpab_user_facing_message_and_does_not_persist(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $this->from(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post(route('household-profiling.members.child-immunization.birth-history.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload([
            'pcab' => 'not-a-valid-pcab-slug',
        ]))->assertSessionHasErrors('pcab');

        $this->assertSame(
            'CPAB selection is invalid.',
            session('errors')->first('pcab')
        );
        $this->assertDatabaseCount('child_birth_histories', 0);
    }

    public function test_birth_history_edit_keeps_pcab_field_name_and_shows_cpab_label(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedPersistedChild();

        $html = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/\bname="pcab"/', $html);
        $this->assertStringContainsString('CPAB from Neonatal Tetanus', $html);
        $this->assertStringNotContainsString('PCAB from Neonatal Tetanus', $html);
        $this->assertStringContainsString('value="at_least_2_doses_1_month_prior"', $html);
        $this->assertStringContainsString('value="tt3_td3_to_tt5_td5_prior"', $html);
        $this->assertTrue(Schema::hasColumn('child_birth_histories', 'pcab'));
    }
}
