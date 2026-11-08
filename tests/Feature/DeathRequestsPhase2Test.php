<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentStatus;
use App\Support\HealthRecordsDeath;
use App\Support\ResidentVitalStatus;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-07 Phase 2 — Death Requests database cutover and persistence.
 */
class DeathRequestsPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_db_resident_appears_in_death_resident_picker(): void
    {
        $seed = $this->seedPersistedKristine();

        $candidates = HealthRecordsDeath::residentCandidates();
        $match = collect($candidates)->first(
            static fn (array $row): bool => $row['member_id'] === 'MB-002'
                && $row['household_no'] === 'HH-151'
        );

        $this->assertNotNull($match);
        $this->assertSame('Kristine Reyes', $match['full_name']);
        $this->assertSame($seed['resident']->id, Resident::query()->where('member_no', 'MB-002')->value('id'));
    }

    public function test_demo_only_resident_cannot_create_persisted_death_request(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validPayload())
            ->assertNotFound();

        $this->assertSame(0, DeathRequest::query()->count());
    }

    public function test_cross_household_member_spoof_fails_closed(): void
    {
        $householdA = Household::factory()->create(['household_no' => 'HH-700', 'zone' => 'Zone 1']);
        Household::factory()->create(['household_no' => 'HH-800', 'zone' => 'Zone 2']);
        $resident = Resident::factory()->create([
            'household_id' => $householdA->id,
            'member_no' => 'MB-010',
            'first_name' => 'Alpha',
            'last_name' => 'Resident',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-700',
                'memberId' => 'MB-010',
            ]), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, DeathRequest::query()->count());
        $this->assertSame($resident->id, DeathRequest::query()->value('resident_id'));

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-800',
                'memberId' => 'MB-010',
            ]), $this->validPayload([
                'registry_no' => '2026-00999',
                'certificate_no' => 'DC-2026-00999',
            ]))
            ->assertNotFound();

        $this->assertSame(1, DeathRequest::query()->count());
    }

    public function test_canonical_registry_number_is_copied_to_legacy_certificate_no_column(): void
    {
        $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validPayload([
                'registry_no' => '2026-00123',
            ]))
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame('2026-00123', $request->registry_no);
        $this->assertSame('2026-00123', $request->certificate_no);
        $this->assertSame($request->displayRegistryNo(), $request->registry_no);
    }

    public function test_rejected_request_does_not_block_new_submission(): void
    {
        $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validPayload())
            ->assertRedirect();

        $first = DeathRequest::query()->firstOrFail();
        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.reject', $first), [
                'rejection_reason' => 'Incomplete certificate details.',
            ])
            ->assertRedirect(route('death-requests.show', $first));

        $first->refresh();
        $this->assertTrue($first->isRejected());
        $this->assertNotNull($first->resident_id);
        $this->assertSame(0, DeathRequest::query()->pending()->count());
        $this->assertNull(DeathRequest::pendingForResident((int) $first->resident_id));
        $this->assertNull(DeathRequest::pendingForMember('HH-151', 'MB-002'));

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validPayload([
                'registry_no' => '2026-00999',
                'certificate_no' => 'DC-2026-00999',
                'death_certificate' => UploadedFile::fake()->create('resubmit.pdf', 80, 'application/pdf'),
            ]))
            ->assertRedirect(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, DeathRequest::query()->count());
        $this->assertSame(1, DeathRequest::query()->pending()->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
    }

    public function test_double_approval_is_rejected_without_duplicate_status(): void
    {
        $this->submitPending();

        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $request))
            ->assertRedirect();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.approve', $request))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame(1, ResidentStatus::query()->count());
        $this->assertTrue(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
    }

    public function test_verify_page_displays_registry_number_without_certificate_no(): void
    {
        $this->submitPending([
            'registry_no' => '2026-00123',
        ]);

        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('death-requests.show', $request))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Registry Number</dt>', $html);
        $this->assertStringNotContainsString('>Certificate No.</dt>', $html);
        $this->assertStringContainsString('2026-00123', $html);
        $this->assertStringNotContainsString('DC-2026-00451', $html);
    }

    public function test_zone_options_derive_from_persisted_households(): void
    {
        Household::factory()->create(['household_no' => 'HH-901', 'zone' => 'Zone 3']);
        Household::factory()->create(['household_no' => 'HH-902', 'zone' => 'Zone 1']);
        Household::factory()->create(['household_no' => 'HH-903', 'zone' => 'Zone 1']);

        $zones = HealthRecordsDeath::zones();

        $this->assertSame(['Zone 1', 'Zone 3'], $zones);
    }

    public function test_admin_death_requests_list_reads_db_records(): void
    {
        $this->submitPending();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('death-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringContainsString('Review', $html);

        $request = DeathRequest::query()->firstOrFail();
        $this->assertStringContainsString(
            route('death-requests.show', $request),
            $html
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => '2026-00123',
            'certificate_no' => 'DC-2026-00451',
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ], $overrides);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedKristine(): array
    {
        $household = Household::query()->where('household_no', 'HH-151')->first();
        if ($household === null) {
            $household = Household::factory()->create([
                'household_no' => 'HH-151',
                'zone' => 'Zone 2',
                'street' => 'Layuan St.',
                'address' => 'Layuan St., Brgy. La Medalla',
            ]);
        }

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->where('member_no', 'MB-002')
            ->first();

        if ($resident === null) {
            $resident = Resident::factory()->create([
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
            ]);
        }

        return [
            'household' => $household,
            'resident' => $resident,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function submitPending(array $overrides = []): void
    {
        $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $this->validPayload($overrides))
            ->assertRedirect();
    }
}
