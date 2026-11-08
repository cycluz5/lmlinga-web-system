<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Services\AnnouncementAudienceMatcher;
use App\Support\AnnouncementAgePreset;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Read-only Announcement audience targeting foundation.
 * No announcement persistence or notification fan-out.
 */
class AnnouncementAudienceMatcherTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementAudienceMatcher $matcher;

    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = new AnnouncementAudienceMatcher;
        $this->asOf = Carbon::parse('2026-08-27')->startOfDay();
    }

    public function test_all_residents_all_zones_returns_official_residents_with_households(): void
    {
        $zone1 = $this->household('HH-A1', 'Zone 1');
        $zone2 = $this->household('HH-A2', 'Zone 2');
        $r1 = $this->resident($zone1, '1990-01-01', 'No');
        $r2 = $this->resident($zone2, '2010-01-01', 'Yes');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'all',
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$r1->getKey(), $r2->getKey()], $keys->all());
        $this->assertSame(2, $this->matcher->count([
            'target_group' => 'all',
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]));
    }

    public function test_age_0_to_6_months_boundaries(): void
    {
        $hh = $this->household('HH-B1', 'Zone 1');

        $exact0 = $this->resident($hh, '2026-08-27');
        $exact6 = $this->resident($hh, '2026-02-27');
        $justOver6 = $this->resident($hh, '2026-01-27');
        $tooOld = $this->resident($hh, '2025-08-27');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_presets' => ['infants_0_6'],
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertEqualsCanonicalizing([$exact0->getKey(), $exact6->getKey()], $keys->all());
        $this->assertFalse($keys->contains($justOver6->getKey()));
        $this->assertFalse($keys->contains($tooOld->getKey()));
    }

    public function test_age_7_to_11_months_boundaries(): void
    {
        $hh = $this->household('HH-C1', 'Zone 1');

        $exact7 = $this->resident($hh, '2026-01-27');
        $exact11 = $this->resident($hh, '2025-09-27');
        $under = $this->resident($hh, '2026-02-27');
        $over = $this->resident($hh, '2025-08-27');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_presets' => ['infants_7_11'],
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertEqualsCanonicalizing([$exact7->getKey(), $exact11->getKey()], $keys->all());
        $this->assertFalse($keys->contains($under->getKey()));
        $this->assertFalse($keys->contains($over->getKey()));
    }

    public function test_age_1_to_5_years_boundaries(): void
    {
        $hh = $this->household('HH-D1', 'Zone 1');

        $exact1 = $this->resident($hh, '2025-08-27');
        $includedNear6 = $this->resident($hh, '2020-08-28');
        $turns6 = $this->resident($hh, '2020-08-27');
        $under1 = $this->resident($hh, '2025-09-27');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_presets' => ['young_children'],
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertTrue($keys->contains($exact1->getKey()));
        $this->assertTrue($keys->contains($includedNear6->getKey()));
        $this->assertFalse($keys->contains($turns6->getKey()));
        $this->assertFalse($keys->contains($under1->getKey()));
    }

    public function test_custom_age_range_in_months(): void
    {
        $hh = $this->household('HH-E1', 'Zone 1');
        $match = $this->resident($hh, '2025-12-27');
        $miss = $this->resident($hh, '2025-08-27');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_range_months' => ['min' => 7, 'max' => 9],
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$match->getKey()], $keys->all());
        $this->assertFalse($keys->contains($miss->getKey()));
    }

    public function test_custom_age_range_in_years_normalizes_to_months(): void
    {
        $this->assertSame(24, AnnouncementAgePreset::toMonths(2, 'years'));
        $this->assertSame(6, AnnouncementAgePreset::toMonths(6, 'months'));

        $hh = $this->household('HH-F1', 'Zone 1');
        $match = $this->resident($hh, '2023-08-27');
        $miss = $this->resident($hh, '2020-08-27');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_range_months' => [
                'min' => AnnouncementAgePreset::toMonths(2, 'years'),
                'max' => AnnouncementAgePreset::toMonths(4, 'years'),
            ],
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertTrue($keys->contains($match->getKey()));
        $this->assertFalse($keys->contains($miss->getKey()));
    }

    public function test_active_maternal_only_pregnancy_status_active(): void
    {
        $hh = $this->household('HH-G1', 'Zone 1');
        $active = $this->resident($hh, '1995-01-01');
        $completed = $this->resident($hh, '1996-01-01');
        $none = $this->resident($hh, '1997-01-01');

        MaternalPregnancy::factory()->create([
            'resident_id' => $active->getKey(),
            'status' => MaternalPregnancy::STATUS_ACTIVE,
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $completed->getKey(),
            'status' => 'completed',
        ]);

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'active_maternal',
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$active->getKey()], $keys->all());
        $this->assertFalse($keys->contains($completed->getKey()));
        $this->assertFalse($keys->contains($none->getKey()));
    }

    public function test_active_fp_user_only_authoritative_current_flag(): void
    {
        $hh = $this->household('HH-H1', 'Zone 1');
        $yes = $this->resident($hh, '1990-01-01', 'Yes');
        $no = $this->resident($hh, '1991-01-01', 'No');
        $na = $this->resident($hh, '1992-01-01', 'N/A');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'active_fp_user',
            'zone_mode' => 'all',
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$yes->getKey()], $keys->all());
        $this->assertFalse($keys->contains($no->getKey()));
        $this->assertFalse($keys->contains($na->getKey()));
    }

    public function test_age_group_plus_zone_1_is_intersection(): void
    {
        $z1 = $this->household('HH-I1', 'Zone 1');
        $z2 = $this->household('HH-I2', 'Zone 2');

        $match = $this->resident($z1, '2025-08-27');
        $wrongZone = $this->resident($z2, '2025-08-27');
        $wrongAge = $this->resident($z1, '1990-01-01');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'age',
            'age_presets' => ['young_children'],
            'zone_mode' => 'specific',
            'zones' => ['Zone 1'],
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$match->getKey()], $keys->all());
        $this->assertFalse($keys->contains($wrongZone->getKey()));
        $this->assertFalse($keys->contains($wrongAge->getKey()));
    }

    public function test_active_maternal_plus_zone_2_is_intersection(): void
    {
        $z1 = $this->household('HH-J1', 'Zone 1');
        $z2 = $this->household('HH-J2', 'Zone 2');

        $match = $this->resident($z2, '1995-01-01');
        $wrongZone = $this->resident($z1, '1995-02-01');

        MaternalPregnancy::factory()->create([
            'resident_id' => $match->getKey(),
            'status' => MaternalPregnancy::STATUS_ACTIVE,
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $wrongZone->getKey(),
            'status' => MaternalPregnancy::STATUS_ACTIVE,
        ]);

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'active_maternal',
            'zone_mode' => 'specific',
            'zones' => ['2'],
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$match->getKey()], $keys->all());
        $this->assertFalse($keys->contains($wrongZone->getKey()));
    }

    public function test_all_residents_specific_zone(): void
    {
        $z1 = $this->household('HH-K1', 'Zone 1');
        $z3 = $this->household('HH-K3', 'Zone 3');

        $in = $this->resident($z1, '2000-01-01');
        $out = $this->resident($z3, '2001-01-01');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'all',
            'zone_mode' => 'specific',
            'zones' => ['Zone 1'],
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$in->getKey()], $keys->all());
        $this->assertFalse($keys->contains($out->getKey()));
    }

    public function test_multiple_specific_zones_match_any_selected_zone(): void
    {
        $z1 = $this->household('HH-L1', 'Zone 1');
        $z2 = $this->household('HH-L2', 'Zone 2');
        $z4 = $this->household('HH-L4', 'Zone 4');

        $a = $this->resident($z1, '2000-01-01');
        $b = $this->resident($z2, '2001-01-01');
        $c = $this->resident($z4, '2002-01-01');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'all',
            'zone_mode' => 'specific',
            'zones' => ['Zone 1', 'Zone 2'],
            'as_of' => $this->asOf,
        ]);

        $this->assertEqualsCanonicalizing([$a->getKey(), $b->getKey()], $keys->all());
        $this->assertFalse($keys->contains($c->getKey()));
    }

    public function test_zone_normalization_trims_and_accepts_numeric_labels(): void
    {
        $hh = $this->household('HH-M1', 'Zone 1 ');
        $match = $this->resident($hh, '2000-01-01');

        $keys = $this->matcher->matchingResidentKeys([
            'target_group' => 'all',
            'zone_mode' => 'specific',
            'zones' => [' 1 '],
            'as_of' => $this->asOf,
        ]);

        $this->assertSame([$match->getKey()], $keys->all());
        $this->assertSame('Zone 1', $this->matcher->normalizeZoneLabel('zone 1'));
    }

    public function test_rejects_client_supplied_recipient_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->matcher->count([
            'target_group' => 'all',
            'zone_mode' => 'all',
            'resident_ids' => [1, 2, 3],
        ]);
    }

    private function household(string $householdNo, string $zone): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => $zone,
        ]);
    }

    private function resident(Household $household, string $birthday, string $fpUser = 'No'): Resident
    {
        return Resident::factory()->create([
            'household_id' => $household->getKey(),
            'birthday' => $birthday,
            'fp_user' => $fpUser,
        ]);
    }
}
