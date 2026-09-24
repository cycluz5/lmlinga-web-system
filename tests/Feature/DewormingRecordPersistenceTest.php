<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\DewormingRecordService;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB-06 Phase 3 — Deworming record persistence for household profiling workflow.
 */
class DewormingRecordPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedEligiblePersistedChild(?string $birthday = null): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-920',
            'zone' => 'Zone 1',
            'street' => 'Test St.',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-920',
            'first_name' => 'Baby',
            'last_name' => 'Deworm',
            'relation' => 'Son',
            'birthday' => $birthday ?? now()->subMonths(6)->format('Y-m-d'),
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
            'year' => 2026,
            'round' => '1',
            'se_status' => 'NHTS',
            'date_given' => '2026-07-01',
            'remarks' => 'Routine dose',
        ], $overrides);
    }

    public function test_deworming_records_schema_contract(): void
    {
        $this->assertTrue(Schema::hasTable('deworming_records'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'year'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'round'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'se_status'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'date_given'));
        $this->assertTrue(Schema::hasColumn('deworming_records', 'remarks'));
    }

    public function test_persisted_eligible_resident_can_create_deworming_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-nutrition', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]));

        $this->assertDatabaseHas('deworming_records', [
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
        ]);

        $record = DewormingRecord::query()->where('resident_id', $resident->id)->first();
        $this->assertNotNull($record);
        $this->assertSame('2026-07-01', $record->date_given->toDateString());
    }

    public function test_future_date_given_is_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01'));
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        $this->from(route('household-profiling.members.child-nutrition', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload(['date_given' => '2026-07-02']))
            ->assertSessionHasErrors('date_given');

        $this->assertSame(0, DewormingRecord::query()->where('resident_id', $resident->id)->count());
    }

    public function test_resident_can_have_multiple_deworming_records_over_time(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
        ]);
        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 2,
        ]);

        $this->assertSame(2, DewormingRecord::query()->where('resident_id', $resident->id)->count());

        $records = HealthRecordsDeworming::recordsForMember(
            $household->household_no,
            $resident->member_no
        );
        $this->assertCount(2, $records);
    }

    public function test_db_backed_records_render_on_deworming_show_page(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => '2026-07-01',
            'remarks' => 'Routine dose',
        ]);

        $html = $this->get(route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('2026', $html);
        $this->assertStringContainsString('NHTS', $html);
        $this->assertStringContainsString('07/01/2026', $html);
        $this->assertStringContainsString('Routine dose', $html);
        $this->assertStringContainsString('data-household-no="'.$household->household_no.'"', $html);
        $this->assertStringContainsString('data-member-id="'.$resident->member_no.'"', $html);
    }

    public function test_resident_id_cannot_be_spoofed_through_request_payload(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-921',
            'birthday' => now()->subMonths(3)->format('Y-m-d'),
        ]);

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), array_merge($this->validPayload(), [
            'resident_id' => $other->id,
        ]))->assertSessionHasErrors('resident_id');

        $this->assertDatabaseCount('deworming_records', 0);
    }

    public function test_demo_only_member_cannot_create_db_deworming_row(): void
    {
        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('deworming_records', 0);
    }

    public function test_cross_household_deworming_store_fails_and_creates_no_row(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-930']);
        $householdB = Household::factory()->create(['household_no' => 'HH-931']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-930',
            'birthday' => now()->subMonths(6)->format('Y-m-d'),
        ]);

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $householdB->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('deworming_records', 0);
    }

    public function test_adult_persisted_resident_can_create_deworming_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17')->startOfDay());
        $birthday = Carbon::now()->subYears(35)->format('Y-m-d');

        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild($birthday);

        $member = ['birthday' => $birthday];
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($member));
        $this->assertTrue(HealthRecordsDeworming::memberCanManageRecords($member));

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())->assertRedirect();

        $this->assertDatabaseCount('deworming_records', 1);
    }

    public function test_senior_persisted_resident_can_create_deworming_record(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17')->startOfDay());
        $birthday = Carbon::now()->subYears(70)->format('Y-m-d');

        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild($birthday);

        $this->assertTrue(HealthRecordsDeworming::memberCanManageRecords(['birthday' => $birthday]));

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), $this->validPayload())->assertRedirect();

        $this->assertDatabaseCount('deworming_records', 1);
    }

    public function test_db_backed_create_form_uses_db_persistence_mode(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        $html = $this->get(route('household-profiling.members.deworming.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-persistence="db"', $html);
        $this->assertStringContainsString(
            route('household-profiling.members.deworming.store', [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ]),
            $html
        );
    }

    public function test_unknown_member_deworming_create_does_not_use_preview_catalog(): void
    {
        $this->get(route('household-profiling.members.deworming.create', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertRedirect(route('household-profiling.members.deworming', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]));
    }

    public function test_resident_has_many_deworming_records_relationship(): void
    {
        ['resident' => $resident] = $this->seedEligiblePersistedChild();

        $record = DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $resident->refresh();
        $this->assertTrue($resident->dewormingRecords->contains($record));
    }

    public function test_duplicate_post_through_store_route_returns_validation_error_not_500(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedEligiblePersistedChild();

        $other = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-921',
            'birthday' => now()->subMonths(3)->format('Y-m-d'),
        ]);

        $storeParams = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
        $createUrl = route('household-profiling.members.deworming.create', $storeParams);

        $this->from($createUrl)
            ->post(route('household-profiling.members.deworming.store', $storeParams), $this->validPayload())
            ->assertRedirect(route('household-profiling.members.child-nutrition', $storeParams));

        $duplicateResponse = $this->from($createUrl)
            ->post(route('household-profiling.members.deworming.store', $storeParams), $this->validPayload());

        $duplicateResponse->assertRedirect($createUrl);
        $duplicateResponse->assertSessionHasErrors('round');
        $duplicateResponse->assertSessionHasErrors([
            'round' => DewormingRecordService::DUPLICATE_MESSAGE,
        ]);
        $this->assertNotSame(500, $duplicateResponse->getStatusCode());

        $this->assertSame(
            1,
            DewormingRecord::query()
                ->where('resident_id', $resident->id)
                ->where('year', 2026)
                ->where('round', 1)
                ->count()
        );
        $this->assertSame(0, DewormingRecord::query()->where('resident_id', $other->id)->count());
    }

    public function test_duplicate_year_and_round_for_same_resident_is_rejected_by_database(): void
    {
        ['resident' => $resident] = $this->seedEligiblePersistedChild();

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
        ]);

        $this->expectException(QueryException::class);

        DewormingRecord::query()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => '2026-07-15',
        ]);
    }

    public function test_physical_resident_deletion_is_restricted_when_deworming_record_exists(): void
    {
        ['resident' => $resident] = $this->seedEligiblePersistedChild();

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
        ]);

        $this->expectException(QueryException::class);

        $resident->forceDelete();
    }

    public function test_deworming_store_route_exists(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.deworming.store'));
    }
}
