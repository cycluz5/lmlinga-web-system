<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\DemoCatalog;
use App\Support\DemoDeath;
use App\Support\ResidentVitalStatus;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Household Profiling Death Information uses Health Records as the writer
 * for DB-backed residents and reads the same DeathRequest row.
 */
class HouseholdProfilingPersistencePhase2DTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
        $this->actingAsStaff(StaffRole::BHW);
    }

    private function dbOnlyHousehold(string $householdNo): Household
    {
        $this->assertNull(DemoCatalog::findHousehold($householdNo));

        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 3',
            'street' => 'DB Death St.',
            'address' => '123 DB Death St., Brgy. La Medalla',
        ]);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function dbMember(string $householdNo = 'HH-870', string $memberNo = 'MB-870'): array
    {
        $household = $this->dbOnlyHousehold($householdNo);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => 'Ana',
            'last_name' => 'DbDeath',
            'sex' => 'Female',
            'relation' => 'Head',
            'birthday' => '1990-03-15',
        ]);

        return compact('household', 'resident');
    }

    /**
     * @return array{householdNo: string, memberId: string}
     */
    private function params(Resident $resident, Household $household): array
    {
        return [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];
    }

    /**
     * @param  array{householdNo: string, memberId: string}  $params
     */
    private function submitThroughHealthRecords(array $params, string $cause, string $date, string $registryNo = 'DC-2026-00451'): void
    {
        $this->post(route('health-records.death.store', $params), [
            'cause_of_death' => $cause,
            'date_of_death' => $date,
            'registry_no' => $registryNo,
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect(route('health-records.death.show', $params));
    }

    public function test_db_member_empty_state_is_neutral_and_create_routes_to_health_records(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember();
        $params = $this->params($resident, $household);

        $html = $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-persistence="database"', $html);
        $this->assertStringContainsString('No death record found.', $html);
        $this->assertStringNotContainsString('Person is still ALIVE', $html);

        $this->get(route('household-profiling.members.death.create', $params))
            ->assertRedirect(route('health-records.death.show', $params));

        $this->post(route('household-profiling.members.death.store', $params), [
            'cause_of_death' => 'Should not persist',
            'date_of_death' => '2026-06-15',
        ])->assertRedirect(route('health-records.death.show', $params));

        $this->assertSame(0, DeathRequest::query()->count());
    }

    public function test_health_records_submission_is_shown_on_household_profiling(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-871', 'MB-871');
        $params = $this->params($resident, $household);

        $this->submitThroughHealthRecords($params, 'Cardiac arrest', '2026-05-01');

        $html = $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-persistence="database"', $html);
        $this->assertStringContainsString('Cardiac arrest', $html);
        $this->assertStringContainsString('05/01/2026', $html);
        $this->assertStringContainsString('Registry Number', $html);
        $this->assertStringContainsString('DC-2026-00451', $html);
        $this->assertStringNotContainsString('Certificate No.', $html);
        $this->assertStringNotContainsString('Death Certificate No.', $html);
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringNotContainsString('Preview only', $html);
        $this->assertSame(1, DeathRequest::query()->where('resident_id', $resident->id)->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased($household->household_no, $resident->member_no));
    }

    public function test_household_profiling_update_does_not_mutate_pending_record(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-873', 'MB-873');
        $params = $this->params($resident, $household);

        $this->submitThroughHealthRecords($params, 'Initial cause', '2026-01-10');
        $id = (int) DeathRequest::query()->where('resident_id', $resident->id)->value('id');

        $this->put(route('household-profiling.members.death.update', $params), [
            'cause_of_death' => 'Updated cause',
            'date_of_death' => '2026-01-11',
        ])->assertRedirect(route('health-records.death.show', $params));

        $this->assertSame(1, DeathRequest::query()->where('resident_id', $resident->id)->count());
        $updated = DeathRequest::query()->findOrFail($id);
        $this->assertSame($resident->id, (int) $updated->resident_id);
        $this->assertSame('Initial cause', $updated->cause_of_death);
        $this->assertSame('2026-01-10', $updated->date_of_death?->format('Y-m-d'));
    }

    public function test_forged_identity_on_household_profiling_post_does_not_create_a_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-874', 'MB-874');
        $other = $this->dbMember('HH-875', 'MB-875');
        $params = $this->params($resident, $household);

        $this->post(route('household-profiling.members.death.store', $params), [
            'cause_of_death' => 'Owned cause',
            'date_of_death' => '2026-03-03',
            'resident_id' => $other['resident']->id,
            'household_id' => $other['household']->id,
            'household_no' => $other['household']->household_no,
            'member_no' => $other['resident']->member_no,
            'member_id' => $other['resident']->member_no,
            'id' => 999999,
        ])->assertRedirect(route('health-records.death.show', $params));

        $this->assertSame(0, DeathRequest::query()->count());
    }

    public function test_cross_household_member_url_cannot_read_foreign_death(): void
    {
        $a = $this->dbMember('HH-876', 'MB-876');
        $b = $this->dbMember('HH-877', 'MB-877');

        $this->submitThroughHealthRecords(
            $this->params($a['resident'], $a['household']),
            'Secret cause A',
            '2026-02-02'
        );

        $forgedParams = [
            'householdNo' => $b['household']->household_no,
            'memberId' => $a['resident']->member_no,
        ];

        $this->get(route('household-profiling.members.death.index', $forgedParams))
            ->assertOk()
            ->assertSee('Member not found', false)
            ->assertDontSee('Secret cause A', false);

        $this->put(route('household-profiling.members.death.update', $forgedParams), [
            'cause_of_death' => 'Hijacked',
            'date_of_death' => '2026-02-03',
        ])->assertRedirect();

        $this->assertDatabaseHas('death_requests', [
            'resident_id' => $a['resident']->id,
            'cause_of_death' => 'Secret cause A',
        ]);
        $this->assertDatabaseMissing('death_requests', [
            'cause_of_death' => 'Hijacked',
        ]);
        $this->assertSame(0, DeathRequest::query()->where('resident_id', $b['resident']->id)->count());
    }

    public function test_resident_demographics_and_health_links_remain_attached(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-878', 'MB-878');
        $before = [
            'id' => $resident->id,
            'household_id' => $resident->household_id,
            'member_no' => $resident->member_no,
            'first_name' => $resident->first_name,
            'last_name' => $resident->last_name,
            'sex' => $resident->sex,
            'birthday' => $resident->birthday?->format('Y-m-d'),
            'relation' => $resident->relation,
        ];

        $assessment = RiskAssessment::factory()->create([
            'resident_id' => $resident->id,
            'assessment_no' => 'RA-878',
        ]);
        $deworming = DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
        ]);

        $this->get(route('household-profiling.members.death.index', $this->params($resident, $household)))
            ->assertOk();

        $resident->refresh();
        $this->assertSame($before, [
            'id' => $resident->id,
            'household_id' => $resident->household_id,
            'member_no' => $resident->member_no,
            'first_name' => $resident->first_name,
            'last_name' => $resident->last_name,
            'sex' => $resident->sex,
            'birthday' => $resident->birthday?->format('Y-m-d'),
            'relation' => $resident->relation,
        ]);
        $this->assertSame($assessment->id, $resident->riskAssessments()->first()?->id);
        $this->assertSame($deworming->id, DewormingRecord::query()->where('resident_id', $resident->id)->value('id'));
    }

    public function test_db_only_household_absent_from_democatalog_reads_health_records_row(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-888', 'MB-888');
        $this->assertNull(DemoCatalog::findHousehold('HH-888'));
        $params = $this->params($resident, $household);

        $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->assertSee('No death record found.', false)
            ->assertSee('data-persistence="database"', false);

        $this->submitThroughHealthRecords($params, 'DB-only cause', '2026-08-01', 'DC-888-0001');

        $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->assertSee('DB-only cause', false)
            ->assertSee('08/01/2026', false)
            ->assertSee('DC-888-0001', false);
    }

    public function test_demodeath_cannot_overwrite_db_backed_member_death(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-879', 'MB-879');
        $params = $this->params($resident, $household);

        DemoDeath::save($household->household_no, $resident->member_no, [
            'cause_of_death' => 'Session poison',
            'date_of_death' => '2020-01-01',
        ]);

        $this->submitThroughHealthRecords($params, 'Authoritative DB cause', '2026-08-08');

        DemoDeath::save($household->household_no, $resident->member_no, [
            'cause_of_death' => 'Session overwrite attempt',
            'date_of_death' => '2019-01-01',
        ]);

        $html = $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Authoritative DB cause', $html);
        $this->assertStringNotContainsString('Session poison', $html);
        $this->assertStringNotContainsString('Session overwrite attempt', $html);
        $this->assertDatabaseHas('death_requests', [
            'resident_id' => $resident->id,
            'cause_of_death' => 'Authoritative DB cause',
        ]);
    }

    public function test_demo_household_profiling_path_does_not_write_death_requests(): void
    {
        $this->post(route('household-profiling.members.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Pneumonia',
                'date_of_death' => '2026-03-15',
            ])
            ->assertRedirect();

        $this->assertSame(0, DeathRequest::query()->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));

        $seed = $this->dbMember('HH-881', 'MB-881');
        $this->submitThroughHealthRecords(
            $this->params($seed['resident'], $seed['household']),
            'Cardiac arrest',
            '2026-07-12'
        );

        $this->assertDatabaseHas('death_requests', [
            'resident_id' => $seed['resident']->id,
            'cause_of_death' => 'Cardiac arrest',
            'registry_no' => 'DC-2026-00451',
            'status' => DeathRequest::STATUS_PENDING,
        ]);
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-881', 'MB-881'));
    }

    public function test_certificate_file_from_health_records_is_shown_on_household_profiling(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->dbMember('HH-880', 'MB-880');
        $params = $this->params($resident, $household);
        $file = UploadedFile::fake()->create('report.pdf', 80, 'application/pdf');

        $this->post(route('health-records.death.store', $params), [
            'cause_of_death' => 'Trauma',
            'date_of_death' => '2026-01-10',
            'registry_no' => 'DC-2026-00451',
            'death_certificate' => $file,
        ])->assertRedirect();

        $row = DeathRequest::query()->where('resident_id', $resident->id)->firstOrFail();
        $this->assertSame('report.pdf', $row->certificate_original_name);
        $this->assertNotSame('pending', $row->certificate_path);
        Storage::disk('death_certificates')->assertExists($row->certificate_path);

        $html = $this->get(route('household-profiling.members.death.index', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('report.pdf', $html);
        $this->assertStringContainsString('Stored with the death record.', $html);
        $this->assertStringContainsString(route('health-records.death.certificate', $params), $html);
    }
}
