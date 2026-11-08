<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\DemoCatalog;
use App\Support\HouseholdMemberResolver;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINAL-2 — production staff routes never silently substitute DemoCatalog.
 */
class HouseholdProfilingFinal2NoDemoFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    public function test_persisted_household_and_member_render_database_values_only(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 3',
            'street' => 'Must Not Appear St.',
            'date_registered' => '2026-03-15',
            'accomplished_by' => 'Must Not Appear Staff',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Persisted',
            'middle_name' => null,
            'last_name' => 'Head',
            'relation' => 'Head',
            'birthday' => '1985-07-09',
            'occupation' => 'Farmer',
            'religion' => 'Islam',
        ]);

        $view = $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('Persisted Head', false)
            ->assertSee('Zone 3', false)
            ->assertSee('Accomplished Date', false)
            ->assertSee('03/15/2026', false)
            ->assertDontSee('Must Not Appear St.', false)
            ->assertDontSee('Must Not Appear Staff', false)
            ->assertDontSee('>Street</span>', false)
            ->assertDontSee('>Accomplished By</span>', false)
            ->assertDontSee('Kristine Reyes', false)
            ->assertDontSee('data-source="demo"', false)
            ->getContent();

        $this->assertStringContainsString('data-source="db"', $view);

        $member = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ]))
            ->assertOk()
            ->assertSee('Persisted Head', false)
            ->assertSee('Farmer', false)
            ->assertSee('Islam', false)
            ->assertSee('07/09/1985', false)
            ->assertDontSee('Kristine Reyes', false)
            ->assertDontSee('Nurse', false)
            ->assertDontSee('05/04/1991', false)
            ->assertDontSee('May 4, 1991', false)
            ->assertDontSee('Demo preview for MB-010', false)
            ->getContent();

        $this->assertStringContainsString('data-source="db"', $member);
        $this->assertStringContainsString('Registered member MB-010 in household HH-001.', $member);
    }

    public function test_unknown_household_and_member_do_not_resolve_demo_catalog(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());

        $resolver = app(HouseholdMemberResolver::class);
        $this->assertNull($resolver->resolveHousehold('HH-151'));
        $this->assertNull($resolver->resolveMember('HH-151', 'MB-001'));

        $before = [
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
        ];

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false)
            ->assertDontSee('data-source="demo"', false)
            ->assertDontSee('Layuan St.', false);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))
            ->assertOk()
            ->assertSee('Member not found', false)
            ->assertDontSee('Kristine Reyes', false)
            ->assertDontSee('data-source="demo"', false);

        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->assertSee('Household not found', false)
            ->assertDontSee('Kristine Reyes', false);

        $this->assertSame($before['households'], Household::query()->count());
        $this->assertSame($before['residents'], Resident::query()->count());
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());
    }

    public function test_cross_household_member_access_is_not_found(): void
    {
        $alpha = Household::factory()->create(['household_no' => '121', 'zone' => 'Zone 1']);
        $beta = Household::factory()->create(['household_no' => '122', 'zone' => 'Zone 2']);
        Resident::factory()->create([
            'household_id' => $alpha->id,
            'member_no' => 'MB-121',
            'first_name' => 'Alpha',
            'last_name' => 'Head',
            'relation' => 'Head',
            'occupation' => 'Teacher',
            'religion' => 'Roman Catholic',
            'birthday' => '1990-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $beta->id,
            'member_no' => 'MB-122',
            'first_name' => 'Beta',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);

        $this->get(route('household-profiling.members.show', [
            'householdNo' => '122',
            'memberId' => 'MB-121',
        ]))
            ->assertOk()
            ->assertSee('Member not found', false)
            ->assertDontSee('Alpha Head', false)
            ->assertDontSee('Teacher', false);

        $this->assertNull(app(HouseholdMemberResolver::class)->resolveMember('122', 'MB-121'));
    }

    public function test_legacy_and_three_digit_persisted_routes_work(): void
    {
        $legacy = Household::factory()->create([
            'household_no' => 'HH-001',
            'zone' => 'Zone 1',
            'date_registered' => '2026-02-01',
        ]);
        Resident::factory()->create([
            'household_id' => $legacy->id,
            'member_no' => 'MB-001',
            'first_name' => 'Legacy',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);

        $current = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'date_registered' => '2026-02-02',
        ]);
        Resident::factory()->create([
            'household_id' => $current->id,
            'member_no' => 'MB-121',
            'first_name' => 'Three',
            'last_name' => 'Digit',
            'relation' => 'Head',
        ]);

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-001']))
            ->assertOk()
            ->assertSee('Legacy Head', false)
            ->assertSee('HH 001', false)
            ->assertDontSee('Kristine Reyes', false);

        $this->get(route('household-profiling.view', ['householdNo' => '121']))
            ->assertOk()
            ->assertSee('Three Digit', false)
            ->assertSee('121', false)
            ->assertDontSee('HH-121', false);
    }

    public function test_profiling_index_shows_zone_without_street_and_excludes_catalog(): void
    {
        $household = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 4',
            'street' => 'Cateel Bay St.',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Index',
            'last_name' => 'Head',
            'relation' => 'Head',
        ]);

        $html = $this->get(route('household-profiling.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('>Zone</th>', $html);
        $this->assertStringContainsString('data-label="Zone"', $html);
        $this->assertStringContainsString('Zone 4', $html);
        $this->assertStringContainsString('Index Head', $html);
        $this->assertStringNotContainsString('>Street</th>', $html);
        $this->assertStringNotContainsString('data-label="Street"', $html);
        $this->assertStringNotContainsString('All Streets', $html);
        $this->assertStringNotContainsString('Cateel Bay St.', $html);
        $this->assertStringNotContainsString('data-household-no="HH-151"', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertStringNotContainsString('Demo catalog rows may appear', $html);
    }

    public function test_spot_mapping_and_dashboard_exclude_catalog_and_keep_marker_parity(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));

        $household = Household::factory()->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'latitude' => 13.38,
            'longitude' => 123.43,
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Doi',
            'last_name' => 'Chipi',
            'relation' => 'Head',
        ]);

        $serviceMarkers = app(SpotMappingService::class)->mappedMarkers();
        $serviceNos = array_values(array_column($serviceMarkers, 'householdNo'));
        sort($serviceNos);

        $spotHtml = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $dashHtml = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(['121'], $serviceNos);
        $this->assertStringContainsString('"householdNo":"121"', $spotHtml);
        $this->assertStringContainsString('"householdNo":"121"', $dashHtml);
        $this->assertStringNotContainsString('HH-151', $spotHtml);
        $this->assertStringNotContainsString('HH-151', $dashHtml);
        $this->assertMatchesRegularExpression('/data-markers=\'/', $spotHtml);
        $this->assertMatchesRegularExpression('/data-markers=\'/', $dashHtml);
        $this->assertCount(count($serviceMarkers), $this->markersFromHtml($dashHtml));
        $this->assertCount(count($serviceMarkers), $this->markersFromHtml($spotHtml));
    }

    public function test_explicit_demo_catalog_lookup_still_works_outside_staff_routes(): void
    {
        $demo = DemoCatalog::findHousehold('HH-151');

        $this->assertNotNull($demo);
        $this->assertSame('Kristine Reyes', $demo['houseHead'] ?? null);
        $this->assertNull(Household::query()->where('household_no', 'HH-151')->first());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function markersFromHtml(string $html): array
    {
        if (preg_match('/data-markers=\'(.*?)\'/s', $html, $matches) !== 1) {
            $this->fail('Page is missing data-markers JSON.');
        }

        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
