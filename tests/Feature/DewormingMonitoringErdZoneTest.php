<?php

namespace Tests\Feature;

use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\Resident;
use App\Support\DewormingErdMode;
use App\Support\DewormingMonitoringService;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use App\Support\HouseholdZoneResolver;
use App\Support\ResidentMemberIdentity;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

/**
 * Deworming monitoring — ERD households.purok zone labels and filtering.
 */
class DewormingMonitoringErdZoneTest extends TestCase
{
    use RefreshDatabase;

    private DewormingMonitoringService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-22')->startOfDay());

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        DewormingErdMode::resetCachedState();

        $this->service = app(DewormingMonitoringService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_erd_schema_stores_purok_not_zone(): void
    {
        $this->assertTrue(Schema::hasColumn('households', 'purok'));
        $this->assertFalse(Schema::hasColumn('households', 'zone'));
        $this->assertSame('purok', HouseholdZoneResolver::locationColumn());
    }

    public function test_purok_1_resolves_to_zone_1(): void
    {
        $this->insertRecordedResident('HH-DW-1', '1', 'Sofia', 'Dela Cruz');

        $rows = $this->service->monitoringRowsForYear(2026);
        $row = collect($rows)->first(
            static fn (array $item): bool => ($item['full_name'] ?? '') === 'Sofia Dela Cruz'
        );

        $this->assertNotNull($row);
        $this->assertSame('Zone 1', $row['zone']);
        $this->assertSame(['Zone 1'], $this->service->zonesForRows($rows));
    }

    public function test_purok_2_resolves_to_zone_2(): void
    {
        $this->insertRecordedResident('HH-DW-2', '2', 'Elena', 'Villanueva');

        $rows = $this->service->monitoringRowsForYear(2026);

        $this->assertSame('Zone 2', $rows[0]['zone']);
        $this->assertSame(['Zone 2'], $this->service->zonesForRows($rows));
    }

    public function test_deworming_zone_filter_matches_resolved_purok_labels(): void
    {
        $this->insertRecordedResident('HH-DW-Z1', '1', 'Zone', 'OneMember');
        $this->insertRecordedResident('HH-DW-Z2', '2', 'Zone', 'TwoMember');

        $rows = $this->service->monitoringRowsForYear(2026);
        $zone1 = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['zone'] ?? '') === 'Zone 1'
        ));
        $zone2 = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['zone'] ?? '') === 'Zone 2'
        ));

        $this->assertCount(2, $rows);
        $this->assertCount(1, $zone1);
        $this->assertSame('Zone OneMember', $zone1[0]['full_name']);
        $this->assertCount(1, $zone2);
        $this->assertSame('Zone TwoMember', $zone2[0]['full_name']);
        $this->assertSame(['Zone 1', 'Zone 2'], $this->service->zonesForRows($rows));
    }

    public function test_round_and_date_labels_unchanged_on_erd_purok_households(): void
    {
        $oneRoundId = $this->insertResident('HH-DW-R1', '1', 'Round', 'One');
        $bothRoundsId = $this->insertResident('HH-DW-R2', '2', 'Round', 'Both');

        DewormingRecord::query()->create([
            'resident_id' => $oneRoundId,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-01',
        ]);
        DewormingRecord::query()->create([
            'resident_id' => $bothRoundsId,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-08-29',
        ]);
        DewormingRecord::query()->create([
            'resident_id' => $bothRoundsId,
            'year' => 2026,
            'round' => 2,
            'date_given' => '2026-08-29',
        ]);

        $rows = $this->service->monitoringRowsForYear(2026);
        $byName = collect($rows)->keyBy('full_name');
        $summary = $this->service->summaryCardsForRows($rows);

        $this->assertSame('07/01/2026', $byName['Round One']['july_round']);
        $this->assertSame(HealthRecordsChildCare::EMPTY_RECORD, $byName['Round One']['january_round']);
        $this->assertSame('1-dose', $byName['Round One']['status']);
        $this->assertSame('Zone 1', $byName['Round One']['zone']);

        $this->assertSame('08/29/2026', $byName['Round Both']['july_round']);
        $this->assertSame('08/29/2026', $byName['Round Both']['january_round']);
        $this->assertSame('2-doses', $byName['Round Both']['status']);
        $this->assertSame('Zone 2', $byName['Round Both']['zone']);

        $this->assertSame('2', $summary['first_round']);
        $this->assertSame('1', $summary['second_round']);
    }

    public function test_member_detail_and_create_render_zone_2_from_erd_purok(): void
    {
        $residentId = $this->insertResident('HH-DW-DET', '2', 'Elena', 'Villanueva');
        $memberId = ResidentMemberIdentity::syntheticMemberId($residentId);

        $profile = HealthRecordsDeworming::findChildForMember('HH-DW-DET', $memberId);
        $this->assertNotNull($profile);
        $this->assertSame('Elena Villanueva', $profile['full_name']);
        $this->assertSame('Zone 2', $profile['zone']);
        $this->assertArrayNotHasKey('mother_name', $profile);
        $this->assertArrayNotHasKey('address_line', $profile);

        $html = view('pages.health-records.partials.child-care-deworming-profile', [
            'child' => $profile,
        ])->render();

        $this->assertDewormingProfileZoneFromPurok($html, 2);
        $this->assertStringContainsString('Elena Villanueva', $html);
    }

    private function insertRecordedResident(
        string $householdNo,
        string $purok,
        string $firstName,
        string $lastName,
    ): int {
        $residentId = $this->insertResident($householdNo, $purok, $firstName, $lastName);

        DewormingRecord::query()->create([
            'resident_id' => $residentId,
            'year' => 2026,
            'round' => 1,
            'date_given' => '2026-07-01',
        ]);

        return $residentId;
    }

    private function insertResident(
        string $householdNo,
        string $purok,
        string $firstName,
        string $lastName,
    ): int {
        $householdId = (int) DB::table('households')->insertGetId([
            'household_no' => $householdNo,
            'purok' => $purok,
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
            'household_type' => 'NHTS',
            'date_registered' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');

        return (int) DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $lastName,
            'relation_to_household_head' => 'Daughter',
            'birthday' => now()->subMonths(8)->format('Y-m-d'),
            'sex' => 'Female',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'resident_id');
    }

    private function assertDewormingProfileZoneFromPurok(string $html, int $zoneNumber): void
    {
        preg_match('/<article class="lml-hr-cc-nr__profile"[\s\S]*?<\/article>/u', $html, $profileMatch);
        $this->assertNotEmpty($profileMatch[0] ?? null);
        $profile = $profileMatch[0];

        $this->assertStringContainsString('<dt>Zone</dt>', $profile);
        $this->assertMatchesRegularExpression(
            '/<dt>\s*Zone\s*<\/dt>\s*<dd>\s*Zone '.$zoneNumber.'\s*</u',
            $profile
        );
        $this->assertStringNotContainsString('Purok '.$zoneNumber, $profile);
        $this->assertStringNotContainsString("Mother's Name", $profile);
        $this->assertStringNotContainsString('<dt>Address</dt>', $profile);
    }
}
