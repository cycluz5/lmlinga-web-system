<?php

namespace Tests\Feature;

use App\Support\AtRestRecord;
use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\User;
use App\Support\DeathRecordsErdMode;
use App\Support\DemoDeath;
use App\Support\ResidentMemberIdentity;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Refinements 19–20: Household Profiling reads the same death_records row
 * as Health Records. Registry Number is stored on death_certificate_no.
 */
class HouseholdProfilingDeathSharedSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
        ClientTestingErdSchema::ensure();
        $this->assertTrue(DeathRecordsErdMode::isActive());
    }

    public function test_hr_created_erd_row_appears_on_household_profiling_with_matching_fields(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ];

        $this->submitDeath($staff['bhw'], $params, [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
        ]);

        $this->assertSame(1, DB::table('death_records')->count());

        $hp = $this->get(route('household-profiling.members.death.index', $params));
        $hp->assertOk();
        $hpHtml = $hp->getContent();

        $hr = $this->get(route('health-records.death.show', $params));
        $hr->assertOk();
        $hrHtml = $hr->getContent();

        $this->assertStringContainsString('data-persistence="database"', $hpHtml);
        $this->assertStringContainsString('Cardiorespiratory Arrest', $hpHtml);
        $this->assertStringContainsString('Cardiorespiratory Arrest', $hrHtml);
        $this->assertStringContainsString('08/15/2026', $hpHtml);
        $this->assertStringContainsString('08/15/2026', $hrHtml);
        $this->assertStringContainsString('DC-2026-0001', $hpHtml);
        $this->assertStringContainsString('DC-2026-0001', $hrHtml);
        $this->assertSame('DC-2026-0001', (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no'));
        $this->assertSame('DC-2026-0001', DeathRequest::query()->firstOrFail()->displayRegistryNo());
        $this->assertStringContainsString('Pending verification', $hpHtml);
        $this->assertStringContainsString('Pending verification', $hrHtml);

        $certificateUrl = route('health-records.death.certificate', $params);
        $this->assertStringContainsString($certificateUrl, $hpHtml);
        $this->assertStringContainsString($certificateUrl, $hrHtml);

        $this->assertRegistryNumberDisplayed($hpHtml, 'DC-2026-0001', 'household-profiling');
        $this->assertRegistryNumberDisplayed($hrHtml, 'DC-2026-0001', 'health-records');

        $this->assertSame(1, DB::table('death_records')->count());
        $this->assertStringNotContainsString('Person is still ALIVE', $hpHtml);
        $this->assertStringNotContainsString('data-death-edit', $hpHtml);
    }

    public function test_empty_death_records_row_shows_neutral_no_record_state(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);

        $this->actingAs($staff['bhw']);
        session([UiRole::SESSION_KEY => 'bhw']);
        $response = $this->get(route('household-profiling.members.death.index', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-death-mode="empty"', $html);
        $this->assertStringContainsString('No death record found.', $html);
        $this->assertStringContainsString('No death information has been recorded for this resident.', $html);
        $this->assertStringNotContainsString('Person is still ALIVE', $html);
        $this->assertStringNotContainsString('DC-2026-0001', $html);
        $this->assertStringNotContainsString('Cardiorespiratory Arrest', $html);
        $this->assertSame(
            1,
            preg_match(
                '/<a\b(?=[^>]*\bdata-death-record-cta\b)(?=[^>]*\bhref="([^"]+)")[^>]*>/i',
                $html,
                $matches
            )
        );
        $ctaHref = html_entity_decode($matches[1], ENT_QUOTES);
        $this->assertSame(route('health-records.death.show', [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ]), $ctaHref);
        $this->assertSame(0, DB::table('death_records')->count());
    }

    public function test_demo_death_session_cannot_override_persisted_death_record(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ];

        $this->submitDeath($staff['bhw'], $params, [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
        ]);

        DemoDeath::save('HH-003', $memberId, [
            'cause_of_death' => 'Session poison',
            'date_of_death' => '2020-01-01',
        ]);

        $html = $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cardiorespiratory Arrest', $html);
        $this->assertStringContainsString('08/15/2026', $html);
        $this->assertStringNotContainsString('Session poison', $html);
        $this->assertStringNotContainsString('01/01/2020', $html);
        $this->assertSame(1, DB::table('death_records')->count());
    }

    public function test_household_profiling_does_not_independently_mutate_or_create_death_rows(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ];

        $this->actingAs($staff['bhw']);
        session([UiRole::SESSION_KEY => 'bhw']);

        $this->get(route('household-profiling.members.death.index', $params))->assertOk();
        $this->assertSame(0, DB::table('death_records')->count());

        $this->post(route('household-profiling.members.death.store', $params), [
            'cause_of_death' => 'Should not persist',
            'date_of_death' => '2026-01-01',
            'resident_id' => 99999,
        ])->assertRedirect(route('health-records.death.show', $params));
        $this->assertSame(0, DB::table('death_records')->count());

        $this->submitDeath($staff['bhw'], $params, [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
        ]);
        $this->assertSame(1, DB::table('death_records')->count());

        $this->put(route('household-profiling.members.death.update', $params), [
            'cause_of_death' => 'Hijacked cause',
            'date_of_death' => '2026-01-02',
        ])->assertRedirect(route('health-records.death.show', $params));

        $this->get(route('household-profiling.members.death.create', $params))
            ->assertRedirect(route('health-records.death.show', $params));
        $this->get(route('household-profiling.members.death.edit', $params))
            ->assertRedirect(route('health-records.death.show', $params));

        $this->assertSame(1, DB::table('death_records')->count());
        $this->assertSame('Cardiorespiratory Arrest', AtRestRecord::open(DB::table('death_records')->value('cause_of_death'), 'death_records', 'cause_of_death'));
        $this->assertSame('2026-08-15', \Illuminate\Support\Carbon::parse((string) DB::table('death_records')->value('date_of_death'))->format('Y-m-d'));
    }

    public function test_cross_household_url_cannot_read_another_residents_death_record(): void
    {
        $staff = $this->seedStaffUsers();
        $first = $this->seedErdHouseholdMember('HH-003', '3', 'Ramon', 'Bautista');
        $second = $this->seedErdHouseholdMember('HH-004', '1', 'Ana', 'Cruz');
        $firstMemberId = ResidentMemberIdentity::memberIdFor($first['resident']);
        $secondMemberId = ResidentMemberIdentity::memberIdFor($second['resident']);

        $this->submitDeath($staff['bhw'], [
            'householdNo' => 'HH-003',
            'memberId' => $firstMemberId,
        ], [
            'cause_of_death' => 'Secret cause A',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'DC-2026-0001',
        ]);

        $this->actingAs($staff['bhw']);
        session([UiRole::SESSION_KEY => 'bhw']);

        $this->get(route('household-profiling.members.death.index', [
            'householdNo' => 'HH-004',
            'memberId' => $secondMemberId,
        ]))
            ->assertOk()
            ->assertSee('No death record found.', false)
            ->assertDontSee('Secret cause A', false);

        $this->get(route('household-profiling.members.death.index', [
            'householdNo' => 'HH-004',
            'memberId' => $firstMemberId,
        ]))
            ->assertOk()
            ->assertSee('Member not found', false)
            ->assertDontSee('Secret cause A', false);

        $this->put(route('household-profiling.members.death.update', [
            'householdNo' => 'HH-004',
            'memberId' => $secondMemberId,
        ]), [
            'cause_of_death' => 'Hijacked',
            'date_of_death' => '2026-01-01',
        ])->assertRedirect(route('health-records.death.show', [
            'householdNo' => 'HH-004',
            'memberId' => $secondMemberId,
        ]));

        $this->assertSame('Secret cause A', AtRestRecord::open(DB::table('death_records')->value('cause_of_death'), 'death_records', 'cause_of_death'));
        $this->assertSame(1, DB::table('death_records')->count());
    }

    public function test_three_digit_household_route_shares_the_same_death_row(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('121', '2', 'Lina', 'Santos');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => '121',
            'memberId' => $memberId,
        ];

        $this->assertSame('121', $seed['household']->household_no);

        $this->submitDeath($staff['bhw'], $params, [
            'cause_of_death' => 'Stroke',
            'date_of_death' => '2026-09-04',
            'registry_no' => 'DC-121-0009',
        ]);

        $hp = $this->get(route('household-profiling.members.death.index', $params));
        $hp->assertOk();
        $hpHtml = $hp->getContent();
        $this->assertStringContainsString('Stroke', $hpHtml);
        $this->assertStringContainsString('09/04/2026', $hpHtml);
        $this->assertRegistryNumberDisplayed($hpHtml, 'DC-121-0009', 'household-profiling');
        $this->assertSame(
            url('/household-profiling/121/members/'.$memberId.'/death'),
            route('household-profiling.members.death.index', $params)
        );
        $this->assertSame(
            url('/health-records/death/121/'.$memberId),
            route('health-records.death.show', $params)
        );

        $hrHtml = $this->get(route('health-records.death.show', $params))->assertOk()->getContent();
        $this->assertStringContainsString('Stroke', $hrHtml);
        $this->assertStringContainsString('09/04/2026', $hrHtml);
        $this->assertRegistryNumberDisplayed($hrHtml, 'DC-121-0009', 'health-records');

        $this->assertSame(1, DB::table('death_records')->count());
    }

    public function test_existing_death_certificate_no_displays_as_registry_number_without_rewrite(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ];

        DB::table('death_records')->insert([
            'resident_id' => $seed['resident']->id,
            'cause_of_death' => 'Pneumonia',
            'date_of_death' => '2026-01-20',
            'death_certificate_no' => 'ABC-123',
            'death_certificate_file_path' => 'existing/abc-123.pdf',
            'verification_status' => 'Pending Verification',
            'submitted_by' => $staff['bhw']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff['bhw']);
        session([UiRole::SESSION_KEY => 'bhw']);

        $hpHtml = $this->get(route('household-profiling.members.death.index', $params))->assertOk()->getContent();
        $hrHtml = $this->get(route('health-records.death.show', $params))->assertOk()->getContent();

        $this->assertSame('ABC-123', DeathRequest::query()->firstOrFail()->displayRegistryNo());
        $this->assertRegistryNumberDisplayed($hpHtml, 'ABC-123', 'household-profiling');
        $this->assertRegistryNumberDisplayed($hrHtml, 'ABC-123', 'health-records');
        $this->assertStringContainsString('01/20/2026', $hpHtml);
        $this->assertStringContainsString('01/20/2026', $hrHtml);

        $this->actingAs($staff['admin']->fresh(['currentAppointment']));
        $staff['admin']->syncUiRoleSession();
        $adminHtml = $this->get(route('death-requests.show', DeathRequest::query()->firstOrFail()))
            ->assertOk()
            ->getContent();
        $this->assertRegistryNumberDisplayed($adminHtml, 'ABC-123', 'admin-review');
        $this->assertSame('ABC-123', (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no'));
        $this->assertSame(1, DB::table('death_records')->count());
    }

    public function test_death_certificate_no_is_registry_number_on_hr_hp_and_admin(): void
    {
        $staff = $this->seedStaffUsers();
        $seed = $this->seedErdHouseholdMember('HH-003', '3');
        $memberId = ResidentMemberIdentity::memberIdFor($seed['resident']);
        $params = [
            'householdNo' => 'HH-003',
            'memberId' => $memberId,
        ];

        $this->submitDeath($staff['bhw'], $params, [
            'cause_of_death' => 'Cardiorespiratory Arrest',
            'date_of_death' => '2026-08-15',
            'registry_no' => 'REG-2026-0042',
        ]);

        $stored = (string) AtRestRecord::open(DB::table('death_records')->value('death_certificate_no'), 'death_records', 'death_certificate_no');
        $this->assertSame('REG-2026-0042', $stored);
        $this->assertSame($stored, DeathRequest::query()->firstOrFail()->displayRegistryNo());

        $this->actingAs($staff['bhw']);
        session([UiRole::SESSION_KEY => 'bhw']);
        $hpHtml = $this->get(route('household-profiling.members.death.index', $params))->assertOk()->getContent();
        $hrHtml = $this->get(route('health-records.death.show', $params))->assertOk()->getContent();
        $this->assertRegistryNumberDisplayed($hpHtml, $stored, 'household-profiling');
        $this->assertRegistryNumberDisplayed($hrHtml, $stored, 'health-records');

        $this->actingAs($staff['admin']->fresh(['currentAppointment']));
        $staff['admin']->syncUiRoleSession();
        $adminHtml = $this->get(route('death-requests.show', DeathRequest::query()->firstOrFail()))
            ->assertOk()
            ->getContent();
        $this->assertRegistryNumberDisplayed($adminHtml, $stored, 'admin-review');
        $this->assertStringContainsString('Death Certificate', $adminHtml);
    }

    private function assertRegistryNumberDisplayed(string $html, string $registryNo, string $surface): void
    {
        $this->assertStringContainsString('Registry Number', $html, $surface.' must label Registry Number');
        $this->assertStringNotContainsString('Certificate No.', $html, $surface.' must not show Certificate No.');
        $this->assertStringNotContainsString('Certificate Number', $html, $surface.' must not show Certificate Number');
        $this->assertStringNotContainsString('Death Certificate No.', $html, $surface.' must not show Death Certificate No.');

        if ($surface === 'household-profiling') {
            $this->assertMatchesRegularExpression(
                '/data-death-view-registry[^>]*>\s*'.preg_quote($registryNo, '/').'/u',
                $html,
                $surface.' Registry Number field must show '.$registryNo
            );
            $this->assertDoesNotMatchRegularExpression(
                '/data-death-view-registry[^>]*>\s*—/u',
                $html,
                $surface.' must not show Registry Number as an empty dash when death_certificate_no is set'
            );

            return;
        }

        $this->assertMatchesRegularExpression(
            '/<dt>\s*Registry Number\s*<\/dt>\s*<dd>\s*'.preg_quote($registryNo, '/').'\s*<\/dd>/u',
            $html,
            $surface.' Registry Number field must show '.$registryNo
        );
    }

    /**
     * @return array{bhw: User, admin: User}
     */
    private function seedStaffUsers(): array
    {
        $bhwId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Angela',
            'last_name' => 'Reyes',
            'email' => 'angela.reyes.shared.death@lamedalla.local',
            'username' => 'angela.reyes.shared.death',
            'password' => bcrypt('password'),
            'status' => 'Active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $adminId = (int) DB::table('user_management')->insertGetId([
            'first_name' => 'Admin',
            'last_name' => 'Reviewer',
            'email' => 'admin.reviewer.shared.death@lamedalla.local',
            'username' => 'admin.reviewer.shared.death',
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
            'sex' => $firstName === 'Ana' || $firstName === 'Lina' ? 'Female' : 'Male',
            'civil_status' => 'Single',
        ]);

        return compact('household', 'resident');
    }

    /**
     * @param  array{householdNo: string, memberId: string}  $params
     * @param  array{cause_of_death: string, date_of_death: string, registry_no: string}  $payload
     */
    private function submitDeath(User $bhw, array $params, array $payload): void
    {
        $this->actingAs($bhw);
        session([UiRole::SESSION_KEY => 'bhw']);

        $this->post(route('health-records.death.store', $params), [
            'cause_of_death' => $payload['cause_of_death'],
            'date_of_death' => $payload['date_of_death'],
            'registry_no' => $payload['registry_no'],
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect(route('health-records.death.show', $params));
    }
}
