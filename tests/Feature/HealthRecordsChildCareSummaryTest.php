<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\ChildBirthHistory;
use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsChildCare;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Health Records → Child Care barangay-wide summary.
 */
class HealthRecordsChildCareSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_care_summary_page_renders(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $response->assertSee('data-lml-hr-child-care', false);
        $response->assertSee('>Total</', false);
        $response->assertSee('Female', false);
        $response->assertSee('Male', false);
        $response->assertDontSee('Total Infants', false);
        $response->assertDontSee('data-hr-cc-add', false);
    }

    public function test_health_records_child_care_sidebar_is_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('child-care', UiRole::sidebarActiveKey());
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__sublink[^>]*aria-current="page"[^>]*>[\s\S]*>Child Care</u',
            $html
        );
    }

    public function test_empty_database_shows_zero_summary_and_empty_state(): void
    {
        $rows = HealthRecordsChildCare::rows();
        $summary = HealthRecordsChildCare::summaryCounts($rows);

        $this->assertSame([], $rows);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['female']);
        $this->assertSame(0, $summary['male']);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('0', $this->extractStatValue($html, 'total'));
        $this->assertSame('0', $this->extractStatValue($html, 'female'));
        $this->assertSame('0', $this->extractStatValue($html, 'male'));
        $this->assertStringContainsString('No child care records are available.', $html);
        $this->assertStringNotContainsString('Haziel H. Santos', $html);
        $this->assertStringNotContainsString('Jacob A. Magistrado', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);

        preg_match_all('/<th scope="col">([^<]+)<\/th>/u', $html, $headerMatches);
        $this->assertSame([
            'Full Name',
            'Age',
            'Birthday',
            'Sex',
            'Action',
        ], $headerMatches[1]);
    }

    public function test_listing_shows_persisted_child_resident(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 2']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-701',
            'first_name' => 'Baby',
            'last_name' => 'Resident',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        ChildBirthHistory::factory()->create([
            'resident_id' => $resident->id,
            'status' => 'Live Birth',
        ]);

        $rows = HealthRecordsChildCare::rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Baby Resident', $rows[0]['full_name']);
        $this->assertSame('Live Birth', $rows[0]['birth_status']);
        $this->assertSame('Female', $rows[0]['sex']);
        $this->assertNotSame('', (string) ($rows[0]['birthday'] ?? ''));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Baby Resident', $html);
        $this->assertStringContainsString('data-has-records="1"', $html);
        $this->assertStringContainsString('Female', $html);
        $this->assertStringContainsString((string) $rows[0]['birthday'], $html);
        $this->assertStringNotContainsString('Birth Status', $html);
        $this->assertStringNotContainsString('Health Status', $html);
    }

    public function test_listing_includes_residents_through_eighteen_years(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);

        Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Teen',
            'last_name' => 'Included',
            'sex' => 'Male',
            'birthday' => now()->subYears(17)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Adult',
            'last_name' => 'Excluded',
            'sex' => 'Female',
            'birthday' => now()->subYears(19)->toDateString(),
        ]);

        $rows = HealthRecordsChildCare::rows();
        $names = array_column($rows, 'full_name');

        $this->assertContains('Teen Included', $names);
        $this->assertNotContains('Adult Excluded', $names);
        $this->assertTrue(HealthRecordsChildCare::isChildCareListingPopulation([
            'birthday' => now()->subYears(18)->toDateString(),
        ]));
        $this->assertFalse(HealthRecordsChildCare::isChildCareListingPopulation([
            'birthday' => now()->subYears(19)->toDateString(),
        ]));
    }

    public function test_listing_shows_no_record_birth_status_when_birth_history_table_absent(): void
    {
        Schema::dropIfExists('child_birth_histories');

        $household = Household::factory()->create(['zone' => 'Zone 2']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-702',
            'first_name' => 'Baby',
            'last_name' => 'NoHistory',
            'sex' => 'Female',
            'birthday' => now()->subMonths(8)->toDateString(),
        ]);

        $rows = HealthRecordsChildCare::rows();

        $this->assertCount(1, $rows);
        $this->assertSame('No record', $rows[0]['birth_status']);
    }

    public function test_vitamin_a_and_deworming_routes_exist_and_link_from_summary(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.vitamin-a'));
        $this->assertTrue(Route::has('health-records.child-care.deworming'));
        $this->assertTrue(Route::has('health-records.child-care.operation-timbang'));

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('health-records.child-care.vitamin-a')).'"',
            $html
        );

        $this->get(route('health-records.child-care.vitamin-a'))->assertOk();
        $this->get(route('health-records.child-care.deworming'))->assertOk();
        $this->get(route('health-records.child-care.operation-timbang'))->assertOk();
    }

    public function test_non_residents_entry_point_is_absent_from_child_care_ui(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(0, substr_count($html, 'data-hr-cc-non-residents'));
    }

    public function test_operation_timbang_keeps_child_care_sidebar_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.operation-timbang'));

        $response->assertOk();
        $this->assertSame('child-care', UiRole::sidebarActiveKey());
    }

    public function test_filter_controls_are_present(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('placeholder="Search resident"', $html);
        $this->assertStringContainsString('data-hr-cc-age', $html);
        $this->assertStringContainsString('data-hr-cc-age-custom', $html);
        $this->assertStringContainsString('data-hr-cc-age-min', $html);
        $this->assertStringContainsString('data-hr-cc-age-max', $html);
        $this->assertStringContainsString('data-hr-cc-sex', $html);
        $this->assertStringContainsString('0–5 months', $html);
        $this->assertStringContainsString('24–59 months', $html);
        $this->assertStringContainsString('15–18 years', $html);
        $this->assertStringContainsString('>Custom</option>', $html);
    }

    public function test_view_links_target_member_show_route_when_records_exist(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-880', 'zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-880',
            'first_name' => 'Link',
            'last_name' => 'Child',
            'sex' => 'Male',
            'birthday' => now()->subMonths(4)->toDateString(),
        ]);

        $expected = route('household-profiling.members.show', [
            'householdNo' => 'HH-880',
            'memberId' => 'MB-880',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.child-care.index'));

        $response->assertOk();
        $this->assertStringContainsString('href="'.e($expected).'"', $response->getContent());
    }

    public function test_age_filter_options_include_bands_and_custom(): void
    {
        $options = HealthRecordsChildCare::ageFilterOptions();

        $this->assertSame([
            'all' => 'Age',
            '0-5' => '0–5 months',
            '6-11' => '6–11 months',
            '12-23' => '12–23 months',
            '24-59' => '24–59 months',
            '5-9' => '5–9 years',
            '10-14' => '10–14 years',
            '15-18' => '15–18 years',
            'custom' => 'Custom',
        ], $options);

        $this->assertTrue(HealthRecordsChildCare::matchesAgeBand(3, '0-5'));
        $this->assertTrue(HealthRecordsChildCare::matchesAgeBand(200, '15-18'));
        $this->assertTrue(HealthRecordsChildCare::matchesAgeYears(7, 5, 9));
        $this->assertFalse(HealthRecordsChildCare::matchesAgeYears(10, 0, 5));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.child-care.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-max-age-years="'.HealthRecordsChildCare::MAX_AGE_YEARS.'"', $html);
        $this->assertMatchesRegularExpression('/data-hr-cc-age-custom[^>]*\bhidden\b/u', $html);
    }

    private function extractStatValue(string $html, string $key): string
    {
        if (! preg_match('/data-stat="'.$key.'"[^>]*>(\d+)</', $html, $match)) {
            $this->fail("Missing data-stat=\"{$key}\" counter.");
        }

        return $match[1];
    }
}
