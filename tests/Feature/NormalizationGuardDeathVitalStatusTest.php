<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\DeathRecordService;
use App\Support\DeathRecordsErdMode;
use App\Support\HealthRecordsDeath;
use App\Support\ResidentMemberIdentity;
use App\Support\ResidentVitalStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): once a death record is Verified, every current application
 * read treats the resident as Deceased. Asserts only externally visible behavior; it does not
 * decide whether resident_statuses is kept, dropped, or written.
 *
 * Runs on the ERD schema (death_records), which matches the live database.
 */
class NormalizationGuardDeathVitalStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
        ClientTestingErdSchema::ensure();
    }

    /**
     * @return array{bhw: User, admin: User}
     */
    private function seedStaff(): array
    {
        $ids = [];
        foreach (['bhw' => StaffRole::BHW, 'admin' => StaffRole::ADMIN] as $key => $role) {
            $ids[$key] = (int) DB::table('user_management')->insertGetId([
                'first_name' => ucfirst($key),
                'last_name' => 'Guard',
                'email' => "{$key}.vital.guard@example.test",
                'username' => "{$key}.vital.guard",
                'password' => bcrypt('password'),
                'status' => 'Active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            User::query()->findOrFail($ids[$key])->assignCurrentAppointment([
                'role' => $role,
                'assigned_barangay' => 'La Medalla',
                'assigned_zone' => 'Zone 1',
                'date_appointed' => '2020-01-01',
            ]);
        }

        return ['bhw' => User::query()->findOrFail($ids['bhw']), 'admin' => User::query()->findOrFail($ids['admin'])];
    }

    private function loginAs(User $user): void
    {
        $user = $user->fresh(['currentAppointment']);
        $this->actingAs($user);
        $user->syncUiRoleSession();
    }

    private function submitDeath(string $householdNo, string $memberId): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('health-records.death.store', ['householdNo' => $householdNo, 'memberId' => $memberId]), [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0101',
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateRow(string $memberId): array
    {
        $row = collect(HealthRecordsDeath::residentCandidates())
            ->first(static fn (array $r): bool => $r['member_id'] === $memberId);
        $this->assertNotNull($row, 'Resident should be listed in the death resident picker.');

        return $row;
    }

    private function vitalBadge(string $householdNo, string $memberId): string
    {
        $html = (string) $this->get(route('health-records.death.show', ['householdNo' => $householdNo, 'memberId' => $memberId]))
            ->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<span\s+class="lml-hr-death-form__vital[^"]*"\s*>\s*(.*?)\s*<\/span>/s', $html, $m));

        return trim($m[0]);
    }

    public function test_verified_death_record_makes_every_read_treat_the_resident_as_deceased(): void
    {
        $this->assertTrue(DeathRecordsErdMode::isActive());
        $staff = $this->seedStaff();

        $household = Household::query()->create(['household_no' => 'HH-061', 'purok' => '2']);
        $resident = Resident::query()->create([
            'household_id' => $household->id,
            'first_name' => 'Vital',
            'last_name' => 'Guard',
            'birthday' => '1950-01-15',
            'sex' => 'Male',
            'civil_status' => 'Married',
        ]);
        $memberId = ResidentMemberIdentity::memberIdFor($resident);

        // Pending: not deceased anywhere.
        $this->loginAs($staff['bhw']);
        $this->submitDeath('HH-061', $memberId)->assertRedirect();
        $request = DeathRequest::query()->sole();

        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-061', $memberId));
        $this->assertNotSame(ResidentVitalStatus::DECEASED, ResidentVitalStatus::label('HH-061', $memberId, 'Married'));
        $this->assertNotContains('HH-061|'.$memberId, ResidentVitalStatus::deceasedKeys());
        $this->assertNotSame(ResidentVitalStatus::DECEASED, $this->candidateRow($memberId)['vital_label']);
        $this->assertStringNotContainsString('--deceased', $this->vitalBadge('HH-061', $memberId));

        // Admin verification (the business action behind the approve route).
        $this->loginAs($staff['admin']);
        app(DeathRecordService::class)->approve($request->fresh());

        $this->assertSame('Verified', DB::table('death_records')->where('death_record_id', $request->id)->value('verification_status'));

        // Every current read now reports Deceased.
        $this->assertTrue(ResidentVitalStatus::isDeceased('HH-061', $memberId));
        $this->assertSame(ResidentVitalStatus::DECEASED, ResidentVitalStatus::label('HH-061', $memberId, 'Married'));
        $this->assertContains('HH-061|'.$memberId, ResidentVitalStatus::deceasedKeys());

        $row = $this->candidateRow($memberId);
        $this->assertSame(ResidentVitalStatus::DECEASED, $row['vital_label']);
        $this->assertFalse($row['can_submit'], 'A deceased resident cannot get a new death submission.');

        $badge = $this->vitalBadge('HH-061', $memberId);
        $this->assertStringContainsString('lml-hr-death-form__vital--deceased', $badge);
        $this->assertStringContainsString('Deceased', $badge);

        // A second death submission for the same resident is refused.
        $this->loginAs($staff['bhw']);
        $this->submitDeath('HH-061', $memberId)->assertSessionHasErrors('cause_of_death');
        $this->assertSame(1, DB::table('death_records')->count());
    }
}
