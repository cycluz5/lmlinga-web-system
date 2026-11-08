<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\DewormingMonitoringService;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DB-06 Phase 4 — Deworming barangay-wide monitoring DB aggregation.
 */
class DewormingMonitoringDbTest extends TestCase
{
    use RefreshDatabase;

    private DewormingMonitoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-22')->startOfDay());
        $this->actingAsStaff(\App\Support\StaffRole::BHW);
        $this->service = app(DewormingMonitoringService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedChild(
        string $householdNo,
        string $memberNo,
        string $firstName,
        string $lastName,
        string $birthday,
        string $zone = 'Zone 1',
        string $sex = 'Male',
    ): array {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => $zone,
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthday' => $birthday,
            'sex' => $sex,
        ]);

        return ['household' => $household, 'resident' => $resident];
    }

    private function seedRecord(
        Resident $resident,
        int $year,
        int $round,
        string $dateGiven,
    ): DewormingRecord {
        return DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => $year,
            'round' => $round,
            'date_given' => $dateGiven,
        ]);
    }

    public function test_zero_record_resident_does_not_appear(): void
    {
        $this->seedChild('HH-401', 'MB-401', 'Baby', 'Zero', now()->subMonths(6)->format('Y-m-d'));

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame([], $rows);
    }

    public function test_zero_record_resident_is_omitted_not_shown_as_none(): void
    {
        $this->seedChild('HH-402', 'MB-402', 'No', 'Dose', now()->subMonths(3)->format('Y-m-d'));

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertCount(0, $rows);
        $this->assertNotContains('No Dose', array_column($rows, 'full_name'));
    }

    public function test_adult_and_senior_residents_appear_when_they_have_records(): void
    {
        ['resident' => $adult] = $this->seedChild(
            'HH-404',
            'MB-404',
            'Adult',
            'Member',
            Carbon::now()->subYears(35)->format('Y-m-d')
        );
        ['resident' => $senior] = $this->seedChild(
            'HH-405',
            'MB-405',
            'Senior',
            'Citizen',
            Carbon::now()->subYears(70)->format('Y-m-d')
        );
        $this->seedRecord($adult, 2026, 1, '2026-07-01');
        $this->seedRecord($senior, 2026, 1, '2026-07-02');

        $rows = $this->service->monitoringRowsForYear(2026);
        $names = array_column($rows, 'full_name');

        $this->assertContains('Adult Member', $names);
        $this->assertContains('Senior Citizen', $names);
    }

    /**
     * @return list<array{label: string, birthday: string, first: string, last: string, memberNo: string}>
     */
    private function ageGroupFixtures(): array
    {
        $now = Carbon::now();

        return [
            [
                'label' => 'infant',
                'birthday' => $now->copy()->subMonthsNoOverflow(6)->format('Y-m-d'),
                'first' => 'Infant',
                'last' => 'Child',
                'memberNo' => 'MB-601',
            ],
            [
                'label' => 'teen',
                'birthday' => $now->copy()->subYearsNoOverflow(14)->format('Y-m-d'),
                'first' => 'Teen',
                'last' => 'Member',
                'memberNo' => 'MB-602',
            ],
            [
                'label' => 'adult',
                'birthday' => $now->copy()->subYearsNoOverflow(35)->format('Y-m-d'),
                'first' => 'Adult',
                'last' => 'Member',
                'memberNo' => 'MB-603',
            ],
            [
                'label' => 'senior',
                'birthday' => $now->copy()->subYearsNoOverflow(70)->format('Y-m-d'),
                'first' => 'Senior',
                'last' => 'Citizen',
                'memberNo' => 'MB-604',
            ],
        ];
    }

    public function test_all_age_groups_appear_in_monitoring_with_member_routes(): void
    {
        $householdNo = 600;
        foreach ($this->ageGroupFixtures() as $fixture) {
            ['household' => $household, 'resident' => $resident] = $this->seedChild(
                'HH-'.($householdNo++),
                $fixture['memberNo'],
                $fixture['first'],
                $fixture['last'],
                $fixture['birthday'],
            );

            DewormingRecord::factory()->create([
                'resident_id' => $resident->id,
                'year' => 2026,
                'round' => 1,
                'date_given' => '2026-07-01',
            ]);

            $rows = $this->service->monitoringRowsForYear(2026);
            $match = collect($rows)->first(
                static fn (array $row): bool => ($row['full_name'] ?? '') === $fixture['first'].' '.$fixture['last']
            );

            $this->assertNotNull($match, 'Missing monitoring row for '.$fixture['label']);
            $this->assertSame('1-dose', $match['status']);
            $this->assertSame(
                route('household-profiling.members.deworming', [
                    'householdNo' => $household->household_no,
                    'memberId' => $resident->member_no,
                ]),
                $match['view_url']
            );

            $this->get($match['view_url'])->assertOk();
        }
    }

    public function test_round_one_record_populates_july_column(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-405',
            'MB-405',
            'Round',
            'One',
            now()->subMonths(8)->format('Y-m-d')
        );

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-01',
        ]);

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame('07/01/2026', $rows[0]['july_round']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['january_round']);
    }

    public function test_round_two_record_populates_january_column(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-406',
            'MB-406',
            'Round',
            'Two',
            now()->subMonths(8)->format('Y-m-d')
        );

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 2,
            'date_given' => '2026-01-20',
        ]);

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows[0]['july_round']);
        $this->assertSame('01/20/2026', $rows[0]['january_round']);
    }

    public function test_exactly_one_record_produces_one_dose_status(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-407',
            'MB-407',
            'Single',
            'Dose',
            now()->subMonths(5)->format('Y-m-d')
        );

        DewormingRecord::factory()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
        ]);

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame('1-dose', $rows[0]['status']);
    }

    public function test_both_rounds_produce_two_doses_status(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-408',
            'MB-408',
            'Both',
            'Doses',
            now()->subMonths(5)->format('Y-m-d')
        );

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

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame('2-doses', $rows[0]['status']);
    }

    public function test_summary_first_and_second_round_counts_derive_from_db_rows(): void
    {
        ['resident' => $oneRound] = $this->seedChild(
            'HH-409',
            'MB-409',
            'One',
            'Round',
            now()->subMonths(4)->format('Y-m-d')
        );
        ['resident' => $bothRounds] = $this->seedChild(
            'HH-410',
            'MB-410',
            'Two',
            'Rounds',
            now()->subMonths(4)->format('Y-m-d')
        );
        $this->seedChild('HH-411', 'MB-411', 'No', 'Records', now()->subMonths(4)->format('Y-m-d'));

        DewormingRecord::factory()->create(['resident_id' => $oneRound->id, 'year' => 2026, 'round' => 1]);
        DewormingRecord::factory()->create(['resident_id' => $bothRounds->id, 'year' => 2026, 'round' => 1]);
        DewormingRecord::factory()->create(['resident_id' => $bothRounds->id, 'year' => 2026, 'round' => 2]);

        $rows = $this->service->monitoringRowsForYear(2026);
        $summary = $this->service->summaryCardsForRows($rows);

        $this->assertSame('2', $summary['first_round']);
        $this->assertSame('1', $summary['second_round']);
    }

    public function test_summary_percentage_formulas_are_correct(): void
    {
        ['resident' => $oneRound] = $this->seedChild(
            'HH-412',
            'MB-412',
            'Pct',
            'One',
            now()->subMonths(4)->format('Y-m-d')
        );
        ['resident' => $bothRounds] = $this->seedChild(
            'HH-413',
            'MB-413',
            'Pct',
            'Two',
            now()->subMonths(4)->format('Y-m-d')
        );
        $this->seedChild('HH-414', 'MB-414', 'Pct', 'None', now()->subMonths(4)->format('Y-m-d'));

        DewormingRecord::factory()->create(['resident_id' => $oneRound->id, 'year' => 2026, 'round' => 1]);
        DewormingRecord::factory()->create(['resident_id' => $bothRounds->id, 'year' => 2026, 'round' => 1]);
        DewormingRecord::factory()->create(['resident_id' => $bothRounds->id, 'year' => 2026, 'round' => 2]);

        $rows = $this->service->monitoringRowsForYear(2026);
        $summary = $this->service->summaryCardsForRows($rows);

        $this->assertSame('33%', $summary['received_1_dose_pct']);
        $this->assertSame('33%', $summary['received_2_dose_pct']);
    }

    public function test_zero_eligible_population_produces_zero_percent_summary(): void
    {
        $summary = $this->service->summaryCardsForRows([]);

        $this->assertSame('0', $summary['first_round']);
        $this->assertSame('0', $summary['second_round']);
        $this->assertSame('0%', $summary['received_1_dose_pct']);
        $this->assertSame('0%', $summary['received_2_dose_pct']);
    }

    public function test_zones_derive_from_recorded_household_zone(): void
    {
        ['resident' => $alpha] = $this->seedChild('HH-415', 'MB-415', 'Zone', 'Alpha', now()->subMonths(2)->format('Y-m-d'), 'Zone Alpha');
        ['resident' => $beta] = $this->seedChild('HH-416', 'MB-416', 'Zone', 'Beta', now()->subMonths(2)->format('Y-m-d'), 'Zone Beta');
        $this->seedRecord($alpha, 2026, 1, '2026-07-01');
        $this->seedRecord($beta, 2026, 1, '2026-07-01');

        $rows = $this->service->monitoringRowsForYear(2026);
        $zones = $this->service->zonesForRows($rows);

        $this->assertSame(['Zone Alpha', 'Zone Beta'], $zones);
    }

    public function test_legacy_zone_labels_filter_to_matching_residents(): void
    {
        ['resident' => $one] = $this->seedChild('HH-430', 'MB-430', 'Zone', 'OneKid', now()->subMonths(2)->format('Y-m-d'), 'Zone 1');
        ['resident' => $two] = $this->seedChild('HH-431', 'MB-431', 'Zone', 'TwoKid', now()->subMonths(2)->format('Y-m-d'), 'Zone 2');
        $this->seedRecord($one, 2026, 1, '2026-07-01');
        $this->seedRecord($two, 2026, 1, '2026-07-01');

        $rows = $this->service->monitoringRowsForYear(2026);
        $byName = collect($rows)->keyBy('full_name');

        $this->assertSame('Zone 1', $byName['Zone OneKid']['zone']);
        $this->assertSame('Zone 2', $byName['Zone TwoKid']['zone']);

        $zone1 = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['zone'] ?? '') === 'Zone 1'
        ));
        $zone2 = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['zone'] ?? '') === 'Zone 2'
        ));

        $this->assertCount(1, $zone1);
        $this->assertSame('Zone OneKid', $zone1[0]['full_name']);
        $this->assertCount(1, $zone2);
        $this->assertSame('Zone TwoKid', $zone2[0]['full_name']);
        $this->assertSame(['Zone 1', 'Zone 2'], $this->service->zonesForRows($rows));
    }

    public function test_view_resolves_to_household_profiling_member_deworming(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild(
            'HH-417',
            'MB-417',
            'View',
            'Target',
            now()->subMonths(2)->format('Y-m-d')
        );
        $this->seedRecord($resident, 2026, 1, '2026-07-01');

        $expected = route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame($expected, $rows[0]['view_url']);
    }

    public function test_no_figma_supplemental_profiles_appear_in_monitoring(): void
    {
        $rows = $this->service->monitoringRowsForYear(2026);
        $names = array_column($rows, 'full_name');

        $this->assertNotContains('Andrei B. Malaya', $names);
        $this->assertNotContains('Crisley F. Fernando', $names);
        $this->assertNotContains('Gabriel Allan S. Chua', $names);
    }

    public function test_monitoring_page_uses_db_mode_and_household_view_links(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild(
            'HH-418',
            'MB-418',
            'Page',
            'Child',
            now()->subMonths(2)->format('Y-m-d')
        );
        $this->seedRecord($resident, 2026, 1, '2026-07-01');

        $viewUrl = route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $html = $this->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-dw-data-mode="db"', $html);
        $this->assertStringContainsString('href="'.e($viewUrl).'"', $html);
        $this->assertStringNotContainsString('Andrei B. Malaya', $html);
        $this->assertStringNotContainsString('data-hr-dw-add', $html);
    }

    public function test_legacy_health_records_show_redirects_to_canonical_member_deworming(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild(
            'HH-419',
            'MB-419',
            'Legacy',
            'Redirect',
            now()->subMonths(2)->format('Y-m-d')
        );

        $slug = HealthRecordsDeworming::childKeyFromDisplayName('Legacy Redirect');
        $target = route('household-profiling.members.deworming', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]);

        $this->get(route('health-records.child-care.deworming.show', ['childKey' => $slug]))
            ->assertRedirect($target);
    }

    public function test_legacy_create_route_still_redirects_to_monitoring(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.deworming.create'));

        $this->get(route('health-records.child-care.deworming.create', [
            'childKey' => 'any-child',
        ]))->assertRedirect(route('health-records.child-care.deworming'));
    }

    public function test_age_label_uses_child_care_month_formatter(): void
    {
        $this->seedChild('HH-420', 'MB-420', 'Age', 'Label', now()->subMonths(6)->format('Y-m-d'));
        ['resident' => $resident] = $this->seedChild('HH-421', 'MB-421', 'Age', 'Recorded', now()->subMonths(6)->format('Y-m-d'));
        $this->seedRecord($resident, 2026, 1, '2026-07-01');

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame('6 Months', $rows[0]['age_label']);
        $this->assertStringNotContainsString('yrs old', $rows[0]['age_label']);
    }

    public function test_other_year_record_does_not_list_resident_in_current_year(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-440',
            'MB-440',
            'Other',
            'Year',
            now()->subMonths(8)->format('Y-m-d')
        );
        $this->seedRecord($resident, 2025, 1, '2025-07-01');

        $this->assertSame([], $this->service->monitoringRowsForYear(2026));
        $this->assertCount(1, $this->service->monitoringRowsForYear(2025));
    }

    public function test_resident_a_record_does_not_render_as_resident_b(): void
    {
        ['resident' => $a] = $this->seedChild('HH-441', 'MB-441', 'Alpha', 'One', now()->subMonths(8)->format('Y-m-d'));
        ['resident' => $b] = $this->seedChild('HH-442', 'MB-442', 'Bravo', 'Two', now()->subMonths(8)->format('Y-m-d'));
        $this->seedRecord($a, 2026, 1, '2026-07-01');
        $this->seedRecord($b, 2026, 2, '2026-01-15');

        $rows = collect($this->service->monitoringRowsForYear(2026))->keyBy('full_name');

        $this->assertSame('07/01/2026', $rows['Alpha One']['july_round']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows['Alpha One']['january_round']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $rows['Bravo Two']['july_round']);
        $this->assertSame('01/15/2026', $rows['Bravo Two']['january_round']);
    }

    public function test_household_profiling_store_feeds_monitoring(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedChild(
            'HH-443',
            'MB-443',
            'Persist',
            'Child',
            now()->subMonths(6)->format('Y-m-d')
        );

        $this->post(route('household-profiling.members.deworming.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'year' => 2026,
            'round' => '1',
            'se_status' => 'NHTS',
            'date_given' => '2026-07-01',
            'remarks' => 'Routine dose',
        ])->assertRedirect();

        $rows = $this->service->monitoringRowsForYear(2026);
        $this->assertCount(1, $rows);
        $this->assertSame('Persist Child', $rows[0]['full_name']);
        $this->assertSame('07/01/2026', $rows[0]['july_round']);
    }

    public function test_empty_monitoring_page_shows_no_records_copy(): void
    {
        $html = $this->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No Deworming records found.', $html);
        $this->assertStringNotContainsString('data-hr-dw-row', $html);
    }

    public function test_maternal_deworming_supplementation_does_not_populate_child_care_monitoring(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-444',
            'MB-444',
            'Maternal',
            'Only',
            now()->subMonths(8)->format('Y-m-d')
        );

        \Tests\Support\ErdMaternalCareSchema::ensure();
        $maternalCareId = (int) \Illuminate\Support\Facades\DB::table('maternal_care')->insertGetId([
            'resident_id' => $resident->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], 'maternal_care_id');
        \Illuminate\Support\Facades\DB::table('deworming_supplementation')->insert([
            'maternal_care_id' => $maternalCareId,
            'date_given' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->service->monitoringRowsForYear(2026));
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('deworming_supplementation')->count());
        $this->assertSame(0, DewormingRecord::query()->count());
    }

    public function test_duplicate_logical_round_is_rejected_and_monitoring_stays_one_row(): void
    {
        ['resident' => $resident] = $this->seedChild(
            'HH-445',
            'MB-445',
            'Dup',
            'Round',
            now()->subMonths(8)->format('Y-m-d')
        );
        $this->seedRecord($resident, 2026, 1, '2026-07-01');

        $this->assertCount(1, $this->service->monitoringRowsForYear(2026));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DewormingRecord::query()->create([
            'resident_id' => $resident->id,
            'year' => 2026,
            'round' => 1,
            'se_status' => 'NHTS',
            'date_given' => '2026-07-15',
        ]);
    }
}
