<?php

namespace Tests\Feature;

use App\Support\AtRestRecord;
use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\DeathCertificateStorage;
use App\Support\DeathRecordsErdMode;
use App\Support\HealthRecordsDeath;
use App\Support\HouseholdMemberResolver;
use App\Support\ResidentMemberIdentity;
use App\Support\ResidentVitalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsAtRestStoredField;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class DeathRecordsErdModeTest extends TestCase
{
    use AssertsAtRestStoredField;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
        ClientTestingErdSchema::ensure();
    }

    public function test_erd_mode_activates_when_death_records_exists_without_death_requests(): void
    {
        $this->assertTrue(Schema::hasTable('death_records'));
        $this->assertFalse(Schema::hasTable('death_requests'));
        $this->assertTrue(DeathRecordsErdMode::isActive());
        $this->assertFalse(ResidentMemberIdentity::hasMemberNoColumn());
    }

    public function test_resident_picker_exposes_purok_as_zone(): void
    {
        $seed = $this->seedErdHouseholdMember('HH-003', '3');

        $candidates = HealthRecordsDeath::residentCandidates();
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $match = collect($candidates)->first(
            static fn (array $row): bool => $row['member_id'] === $memberId
        );

        $this->assertNotNull($match);
        $this->assertSame('3', $match['zone']);
        $this->assertSame('HH-003', $match['household_no']);
    }

    public function test_resident_picker_zone_filter_finds_residents(): void
    {
        $first = $this->seedErdHouseholdMember('HH-003', '3', 'Ramon', 'Bautista');
        $second = $this->seedErdHouseholdMember('HH-004', '1', 'Ana', 'Cruz');

        $candidates = HealthRecordsDeath::residentCandidates();
        $zoneThree = HealthRecordsDeath::filterRows($candidates, ['zone' => '3']);
        $zoneOne = HealthRecordsDeath::filterRows($candidates, ['zone' => '1']);

        $memberIdForFirst = ResidentMemberIdentity::memberIdFor($first['resident']);
        $memberIdForSecond = ResidentMemberIdentity::memberIdFor($second['resident']);

        $this->assertTrue(collect($zoneThree)->contains(
            static fn (array $row): bool => $row['member_id'] === $memberIdForFirst
        ));
        $this->assertFalse(collect($zoneThree)->contains(
            static fn (array $row): bool => $row['member_id'] === $memberIdForSecond
        ));
        $this->assertTrue(collect($zoneOne)->contains(
            static fn (array $row): bool => $row['member_id'] === $memberIdForSecond
        ));
    }

    public function test_submission_persists_registry_number_to_death_certificate_no(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $file = UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf');

        $this->loginAs($staff['bhw']);

        $this->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
            'death_certificate' => $file,
        ])->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame(DeathRequest::STATUS_PENDING, $request->status);
        $this->assertSame($seed['resident']->id, $request->resident_id);
        $this->assertSame('DC-2026-0001', (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no'));
        $this->assertSame('DC-2026-0001', $request->displayRegistryNo());
        $this->assertSame($staff['bhw']->id, (int) DB::table('death_records')->value('submitted_by'));
        $this->assertSame('Pending Verification', DB::table('death_records')->value('verification_status'));
        $this->assertNotSame('', trim((string) $request->certificate_path));
        $this->assertTrue(DeathCertificateStorage::exists($request));
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-003', $memberId));
        if (Schema::hasTable('resident_statuses')) {
            $this->assertSame(0, DB::table('resident_statuses')->count());
        }
    }

    public function test_registry_number_is_required_and_capped_at_fifty_characters(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $file = UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf');

        $this->loginAs($staff['bhw']);

        $this->from(route('health-records.death.show', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]))->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'death_certificate' => $file,
        ])->assertRedirect()->assertSessionHasErrors('registry_no');

        $this->from(route('health-records.death.show', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]))->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => str_repeat('A', 51),
            'death_certificate' => $file,
        ])->assertRedirect()->assertSessionHasErrors('registry_no');

        $this->assertSame(0, DB::table('death_records')->count());
    }

    public function test_approval_marks_deceased_with_correct_verifier(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $request = $this->submitPendingErdRecord($staff['bhw'], $seed, $memberId);

        $this->loginAs($staff['admin']);

        $this->post(route('death-requests.approve', $request))
            ->assertRedirect(route('death-requests.show', $request));

        $request->refresh();
        $row = AtRestRecord::openRow('death_records', DB::table('death_records')->where('death_record_id', $request->id)->first());

        $this->assertSame(DeathRequest::STATUS_APPROVED, $request->status);
        $this->assertSame('Verified', $row->verification_status);
        $this->assertSame($staff['admin']->id, (int) $row->verified_by);
        $this->assertNotNull($row->verified_at);
        $this->assertTrue(ResidentVitalStatus::isDeceased('HH-003', $memberId));
        if (Schema::hasTable('resident_statuses')) {
            $this->assertSame(0, DB::table('resident_statuses')->where('status', 'deceased')->count());
        }
    }

    public function test_rejection_stores_reason_and_leaves_resident_active(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $request = $this->submitPendingErdRecord($staff['bhw'], $seed, $memberId);

        $this->loginAs($staff['admin']);

        $this->post(route('death-requests.reject', $request), [
            'rejection_reason' => 'Certificate number does not match the uploaded file.',
        ])->assertRedirect(route('death-requests.show', $request));

        $request->refresh();
        $row = AtRestRecord::openRow('death_records', DB::table('death_records')->where('death_record_id', $request->id)->first());

        $this->assertSame(DeathRequest::STATUS_REJECTED, $request->status);
        $this->assertSame('Rejected', $row->verification_status);
        $this->assertPlaintextStoredField(
            'death_records',
            'rejection_reason',
            'Certificate number does not match the uploaded file.',
            ['death_record_id' => $request->id]
        );
        $this->assertSame('Certificate number does not match the uploaded file.', $request->rejection_reason);
        $this->assertSame($staff['admin']->id, (int) $row->verified_by);
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-003', $memberId));
    }

    public function test_rejected_resubmission_reuses_existing_death_record_row(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $request = $this->submitPendingErdRecord($staff['bhw'], $seed, $memberId);

        $this->loginAs($staff['admin']);
        $this->post(route('death-requests.reject', $request), [
            'rejection_reason' => 'Needs correction.',
        ])->assertRedirect();

        $this->loginAs($staff['bhw']);

        $this->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Updated cause',
            'date_of_death' => '2026-08-16',
            'registry_no' => 'DC-2026-0002',
            'death_certificate' => UploadedFile::fake()->create('updated.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(1, DB::table('death_records')->count());
        $request->refresh();
        $this->assertSame(DeathRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Updated cause', $request->cause_of_death);
        $this->assertSame('DC-2026-0002', $request->displayRegistryNo());
        $this->assertSame('DC-2026-0002', (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no'));
        $this->assertSame($staff['bhw']->id, (int) DB::table('death_records')->value('submitted_by'));
    }

    public function test_certificate_storage_and_download_remain_functional(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $request = $this->submitPendingErdRecord($staff['bhw'], $seed, $memberId);

        $this->loginAs($staff['bhw']);

        $this->get(route('health-records.death.certificate', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]))->assertOk();

        $this->loginAs($staff['admin']);

        $this->get(route('death-requests.certificate', $request))->assertOk();

        $storedPath = (string) DB::table('death_records')->value('death_certificate_file_path');
        $this->assertNotSame('', $storedPath);
        $this->assertNotSame('pending', $storedPath);
        Storage::disk('death_certificates')->assertExists($storedPath);
    }

    public function test_synthetic_member_identity_resolves_for_routes_and_resolver(): void
    {
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);

        $this->assertSame('MB-'.str_pad((string) $seed['resident']->id, 3, '0', STR_PAD_LEFT), $memberId);

        $resolved = app(HouseholdMemberResolver::class)->resolveMember('HH-003', $memberId);
        $this->assertNotNull($resolved);
        $this->assertSame($seed['resident']->id, $resolved['resident']?->id);

        $this->loginAs($this->seedStaffUsers()['bhw']);

        $this->get(route('health-records.death.show', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]))->assertOk();
    }

    public function test_guest_submission_is_rejected_without_creating_a_death_record(): void
    {
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $this->seedStaffUsers();

        $this->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame(0, DB::table('death_records')->count());
    }

    public function test_bhw_cannot_perform_admin_verification_actions(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $request = $this->submitPendingErdRecord($staff['bhw'], $seed, $memberId);

        $this->loginAs($staff['bhw']);
        $this->post(route('death-requests.approve', $request))->assertForbidden();
        $this->post(route('death-requests.reject', $request), [
            'rejection_reason' => 'Should not apply.',
        ])->assertForbidden();
        $this->get(route('death-requests.show', $request))->assertForbidden();
        $this->get(route('death-requests.certificate', $request))->assertForbidden();

        $request->refresh();
        $this->assertSame(DeathRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Pending Verification', DB::table('death_records')->value('verification_status'));
    }

    public function test_posted_certificate_no_cannot_override_registry_number(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);

        $this->loginAs($staff['bhw']);
        $this->post(route('health-records.death.store', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'REG-2026-001',
            'certificate_no' => 'WRONG-999',
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $this->assertSame('REG-2026-001', (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no'));
        $this->assertSame('REG-2026-001', DeathRequest::query()->firstOrFail()->displayRegistryNo());
        $this->assertSame(1, DB::table('death_records')->count());
    }

    /**
     * @return array{bhw: User, admin: User}
     */
    private function seedStaffUsers(): array
    {
        $bhwId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => 'angela.reyes.death.test@lamedalla.local',
            'username' => 'angela.reyes.death.test',
            'password' => bcrypt('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Admin',
            'last_name' => 'Reviewer',
            'email' => 'admin.reviewer.death.test@lamedalla.local',
            'username' => 'admin.reviewer.death.test',
            'password' => bcrypt('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bhw = User::query()->findOrFail($bhwId);
        $admin = User::query()->findOrFail($adminId);

        $bhw->assignCurrentAppointment([
            'role' => StaffRole::BHW,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);
        $admin->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        return [
            'bhw' => $bhw->fresh(['currentAppointment']),
            'admin' => $admin->fresh(['currentAppointment']),
        ];
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedErdHouseholdMember(
        string $householdNo,
        string $purok,
        string $firstName = 'Ramon',
        string $lastName = 'Bautista'
    ): array {
        $household = Household::query()->create([
            'household_no' => $householdNo,
            'purok' => $purok,
        ]);

        $resident = Resident::query()->create([
            'household_id' => $household->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthday' => '1985-01-15',
            'sex' => 'Male',
            'civil_status' => 'Single',
        ]);

        return compact('household', 'resident');
    }

    private function loginAs(User $user): void
    {
        $user = $user->fresh(['currentAppointment']);
        $this->actingAs($user);
        $user->syncUiRoleSession();
    }

    /**
     * @param  array{household: Household, resident: Resident}  $seed
     */
    private function submitPendingErdRecord(User $bhw, array $seed, string $memberId): DeathRequest
    {
        $this->loginAs($bhw);

        $this->post(route('health-records.death.store', [
            'householdNo' => $seed['household']->household_no,
            'memberId' => $memberId,
        ]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        return DeathRequest::query()->firstOrFail();
    }
}
