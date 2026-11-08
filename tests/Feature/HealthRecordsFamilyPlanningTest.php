<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\FamilyPlanningVisit;
use App\Models\Household;
use App\Models\Resident;
use App\Support\FamilyPlanningErdMode;
use App\Support\HealthRecordsFamilyPlanning;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Health Records → Family Planning barangay-wide summary.
 */
class HealthRecordsFamilyPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_planning_route_resolves(): void
    {
        $this->assertTrue(Route::has('health-records.family-planning.index'));
        $this->assertFalse(Route::has('health-records.family-planning'));

        $route = Route::getRoutes()->getByName('health-records.family-planning.index');
        $this->assertNotNull($route);
        $this->assertSame('health-records/family-planning', $route->uri());
    }

    public function test_family_planning_page_renders_successfully(): void
    {
        $this->actingAsStaff(StaffRole::BNS);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-lml-hr-fp', $html);
        $this->assertMatchesRegularExpression(
            '/id="lml-hr-fp-heading"[^>]*>\s*Family Planning\s*</u',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-fp__title', $html);
        $this->assertStringNotContainsString('lml-hr-fp__description', $html);
        $this->assertStringNotContainsString(
            'Record and management of family planning details for monitoring and tracking reproductive health services.',
            $html
        );
    }

    public function test_summary_cards_render_from_database_counts(): void
    {
        $summary = HealthRecordsFamilyPlanning::summaryCounts();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(0, $summary['total']);
        $this->assertSame([], $summary['commodities']);
        $this->assertStringContainsString('Total FP Patients', $html);
        $this->assertStringContainsString('Commodities Type Users', $html);
        $this->assertStringNotContainsString('Due for Follow-ups', $html);
        $this->assertStringNotContainsString('Missed for Follow-ups', $html);
        $this->assertMatchesRegularExpression(
            '/data-fp-stat="total"[^>]*>\s*0\s*</u',
            $html
        );
    }

    public function test_filter_controls_render(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-hr-fp-search', $html);
        $this->assertStringContainsString('placeholder="Search Name"', $html);
        $this->assertStringContainsString('data-hr-fp-zone', $html);
        $this->assertStringContainsString('data-hr-fp-year', $html);
    }

    public function test_empty_database_shows_empty_state_without_demo_names(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame([], HealthRecordsFamilyPlanning::rows());
        $this->assertStringContainsString('data-hr-fp-empty', $html);
        $this->assertStringContainsString('No family planning records are available.', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
        $this->assertStringNotContainsString('Jacob A. Magistrado', $html);
        $this->assertStringNotContainsString('Haziel H. Santos', $html);
        $this->assertStringNotContainsString('data-hr-fp-row', $html);
    }

    public function test_listing_shows_persisted_family_planning_visit(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 3']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Family',
            'last_name' => 'Planning',
            'birthday' => '1992-06-01',
        ]);

        FamilyPlanningVisit::factory()->create([
            'resident_id' => $resident->id,
            'visited_at' => '2026-04-10',
            'commodities' => [['name' => 'Pills', 'quantity' => 1]],
        ]);

        $rows = HealthRecordsFamilyPlanning::rows();
        $this->assertCount(1, $rows);
        $this->assertSame('Family Planning', $rows[0]['full_name']);
        $this->assertSame('Pills', $rows[0]['method']);
        $this->assertSame('2026', $rows[0]['year']);
        $this->assertNotSame('', (string) ($rows[0]['view_url'] ?? ''));

        $this->actingAsStaff(StaffRole::BHW);
        $html = $this->get(route('health-records.family-planning.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Family Planning', $html);
        $this->assertStringContainsString('Pills', $html);
        $this->assertStringContainsString('lml-hr-fp__name-link', $html);
        $this->assertStringContainsString((string) $rows[0]['view_url'], $html);
    }

    public function test_export_control_exists_without_add_button(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('data-hr-fp-add', $html);
        $this->assertStringContainsString('data-hr-fp-export', $html);
        $this->assertStringContainsString('data-hr-fp-toast', $html);
    }

    public function test_sidebar_family_planning_is_real_link_and_active(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.family-planning.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame('family-planning', UiRole::sidebarActiveKey());
        $this->assertStringContainsString(
            'href="'.e(route('health-records.family-planning.index')).'"',
            $html
        );
    }

    public function test_summary_counts_match_database_rows(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $pillsResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Pills',
            'last_name' => 'User',
            'birthday' => '1990-01-01',
        ]);
        $condomResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Condom',
            'last_name' => 'User',
            'birthday' => '1991-01-01',
        ]);
        $secondPillsResident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Second',
            'last_name' => 'Pills',
            'birthday' => '1992-01-01',
        ]);

        FamilyPlanningVisit::factory()->create([
            'resident_id' => $pillsResident->id,
            'visited_at' => '2026-04-10',
            'commodities' => [['name' => 'Pills', 'quantity' => 1]],
        ]);
        FamilyPlanningVisit::factory()->create([
            'resident_id' => $condomResident->id,
            'visited_at' => '2026-04-11',
            'commodities' => [['name' => 'Condom', 'quantity' => 1]],
        ]);
        FamilyPlanningVisit::factory()->create([
            'resident_id' => $secondPillsResident->id,
            'visited_at' => '2026-04-12',
            'commodities' => [['name' => 'Pills', 'quantity' => 1]],
        ]);

        $rows = HealthRecordsFamilyPlanning::rows();
        $summary = HealthRecordsFamilyPlanning::summaryCounts($rows);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(
            [
                ['name' => 'Pills', 'count' => 2],
                ['name' => 'Condom', 'count' => 1],
            ],
            $summary['commodities']
        );
    }

    public function test_remains_independent_of_household_profiling_family_planning(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.family-planning.index'));
        $this->assertTrue(Route::has('health-records.family-planning.index'));

        $hh = Route::getRoutes()->getByName('household-profiling.members.family-planning.index');
        $hr = Route::getRoutes()->getByName('health-records.family-planning.index');

        $this->assertNotNull($hh);
        $this->assertNotNull($hr);
        $this->assertNotSame($hh->uri(), $hr->uri());
    }

    public function test_erd_mode_listing_derives_method_from_first_commodity_given(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::dropIfExists('family_planning');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        Schema::dropIfExists('fp_commodities_given');
        Schema::create('fp_commodities_given', function ($table): void {
            $table->id('commodity_given_id');
            $table->unsignedBigInteger('fp_id');
            $table->string('commodity_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
        FamilyPlanningErdMode::resetCachedState();

        $household = Household::factory()->create(['zone' => 'Zone 1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'first_name' => 'Elena',
            'last_name' => 'Santos',
        ]);

        $fpId = DB::table('family_planning')->insertGetId([
            'resident_id' => $resident->id,
            'visitation_date' => '2026-09-15',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'fp_id');
        DB::table('fp_commodities_given')->insert([
            ['fp_id' => $fpId, 'commodity_name' => 'Pills', 'quantity' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['fp_id' => $fpId, 'commodity_name' => 'Condoms', 'quantity' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = HealthRecordsFamilyPlanning::rows();
        $this->assertCount(1, $rows);
        // Method shows the first commodity recorded for the visit — never
        // blank just because the data lives in the ERD child table.
        $this->assertSame('Pills', $rows[0]['method']);

        Schema::dropIfExists('fp_commodities_given');
        Schema::dropIfExists('family_planning');
        FamilyPlanningErdMode::resetCachedState();
    }
}
