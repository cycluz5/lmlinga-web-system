<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentStatus;
use App\Support\DeathRequestResidentBackfill;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-05 Phase 3 — resident_id FK bridge + Death write identity cutover.
 */
class DeathResidentIdentityPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_schema_has_nullable_resident_id_on_death_tables(): void
    {
        $this->assertTrue(Schema::hasColumn('death_requests', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('resident_statuses', 'resident_id'));
        $this->assertTrue(Schema::hasColumn('death_requests', 'household_no'));
        $this->assertTrue(Schema::hasColumn('death_requests', 'member_id'));
        $this->assertTrue(Schema::hasColumn('resident_statuses', 'household_no'));
        $this->assertTrue(Schema::hasColumn('resident_statuses', 'member_id'));
    }

    public function test_death_submission_persists_resident_id_and_string_identifiers(): void
    {
        $seed = $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame('HH-151', $request->household_no);
        $this->assertSame('MB-002', $request->member_id);
        $this->assertSame($seed['resident']->id, $request->resident_id);
        $this->assertTrue($request->resident()->is($seed['resident']));
    }

    public function test_approval_copies_resident_id_onto_resident_status(): void
    {
        $seed = $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $request))
            ->assertRedirect();

        $status = ResidentStatus::forMember('HH-151', 'MB-002');
        $this->assertNotNull($status);
        $this->assertTrue($status->isDeceased());
        $this->assertSame($seed['resident']->id, $status->resident_id);
        $this->assertSame($request->id, $status->death_request_id);
    }

    public function test_demo_only_member_death_write_fails_closed_without_creating_resident(): void
    {
        $beforeResidents = Resident::query()->count();
        $beforeHouseholds = Household::query()->count();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ])
            ->assertNotFound();

        $this->assertSame(0, DeathRequest::query()->count());
        $this->assertSame($beforeResidents, Resident::query()->count());
        $this->assertSame($beforeHouseholds, Household::query()->count());
    }

    public function test_backfill_maps_household_scoped_member_to_resident_id(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-700']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
        ]);

        $request = DeathRequest::query()->create($this->deathRequestAttributes([
            'household_no' => 'HH-700',
            'member_id' => 'MB-010',
            'resident_id' => null,
        ]));

        $status = ResidentStatus::query()->create([
            'household_no' => 'HH-700',
            'member_id' => 'MB-010',
            'resident_id' => null,
            'status' => ResidentStatus::STATUS_DECEASED,
            'death_request_id' => $request->id,
            'recorded_at' => now(),
        ]);

        $counts = DeathRequestResidentBackfill::run();
        $this->assertSame(1, $counts['death_requests']);
        $this->assertSame(1, $counts['resident_statuses']);

        $request->refresh();
        $status->refresh();
        $this->assertSame($resident->id, $request->resident_id);
        $this->assertSame($resident->id, $status->resident_id);

        $this->assertSame(
            ['death_requests' => 0, 'resident_statuses' => 0],
            DeathRequestResidentBackfill::run()
        );
    }

    public function test_backfill_does_not_match_member_no_outside_household(): void
    {
        $a = Household::factory()->create(['household_no' => 'HH-710']);
        Household::factory()->create(['household_no' => 'HH-711']);
        $residentA = Resident::factory()->create([
            'household_id' => $a->id,
            'member_no' => 'MB-050',
            'first_name' => 'Alpha',
        ]);

        // Same member_no string as residentA, but different household — must stay NULL
        // (member_no is globally unique; scoping is still enforced via household_id join).
        $unmatched = DeathRequest::query()->create($this->deathRequestAttributes([
            'household_no' => 'HH-711',
            'member_id' => 'MB-050',
            'resident_id' => null,
            'resident_name' => 'Should Not Match',
        ]));

        $matched = DeathRequest::query()->create($this->deathRequestAttributes([
            'household_no' => 'HH-710',
            'member_id' => 'MB-050',
            'resident_id' => null,
            'resident_name' => 'Alpha',
            'registry_no' => 'REG-MATCH',
            'certificate_no' => 'REG-MATCH',
            'certificate_path' => 'HH-710/MB-050/1/file.pdf',
            'status' => DeathRequest::STATUS_APPROVED,
        ]));

        DeathRequestResidentBackfill::run();

        $unmatched->refresh();
        $matched->refresh();

        $this->assertNull($unmatched->resident_id);
        $this->assertSame($residentA->id, $matched->resident_id);
    }

    public function test_backfill_leaves_unmatched_demo_rows_null(): void
    {
        $request = DeathRequest::query()->create($this->deathRequestAttributes([
            'household_no' => 'HH-151',
            'member_id' => 'MB-002',
            'resident_id' => null,
        ]));

        $counts = DeathRequestResidentBackfill::run();
        $this->assertSame(0, $counts['death_requests']);
        $this->assertSame(0, $counts['resident_statuses']);

        $request->refresh();
        $this->assertNull($request->resident_id);
        $this->assertSame(0, Resident::query()->count());
        $this->assertSame(0, Household::query()->count());
    }

    public function test_health_destination_identity_resolves_persisted_member(): void
    {
        $this->seedPersistedKristine([
            'first_name' => 'Kristine',
            'last_name' => 'Reyes',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.child-immunization', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->assertOk()
            ->assertSee('Kristine Reyes', false);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.risk-assessment', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->assertOk()
            ->assertSee('Kristine Reyes', false);

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('household-profiling.members.family-planning.index', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->assertOk()
            ->assertSee('Kristine Reyes', false);
    }

    public function test_unknown_production_identity_is_not_found_without_catalog_fallback(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('household-profiling.members.child-nutrition', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('No record found', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
    }

    /**
     * @param  array<string, mixed>  $residentOverrides
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedKristine(array $residentOverrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Spouse',
            'sex' => 'Female',
            'birthday' => '1991-08-12',
            'relationship_status' => 'Married',
            'occupation' => 'Nurse',
        ], $residentOverrides));

        return [
            'household' => $household,
            'resident' => $resident,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function deathRequestAttributes(array $overrides = []): array
    {
        return array_merge([
            'household_no' => 'HH-700',
            'member_id' => 'MB-010',
            'resident_name' => 'Test Resident',
            'resident_sex' => 'Female',
            'resident_age' => 35,
            'zone' => 'Zone 1',
            'household_display_no' => 'HH 700',
            'address' => 'Test Address',
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => 'REG-TEST',
            'certificate_no' => 'REG-TEST',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'pending/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now(),
        ], $overrides);
    }
}
