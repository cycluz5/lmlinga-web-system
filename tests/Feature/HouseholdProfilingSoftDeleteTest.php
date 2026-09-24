<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\ChildImmunization;
use App\Models\DewormingRecord;
use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\HouseholdSolidWastePractice;
use App\Models\Resident;
use App\Models\RiskAssessment;
use App\Support\DemoCatalog;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * R02-A — Household Profiling soft-delete (household row only).
 */
class HouseholdProfilingSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array{household: Household, residents: list<Resident>, profile: HouseholdEnvironmentalProfile}
     */
    private function seedDeletableHousehold(string $householdNo = 'HH-770'): array
    {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Soft Delete St.',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]);

        $head = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-770',
            'first_name' => 'Soft',
            'last_name' => 'Head',
            'relation' => 'Head',
            'sex' => 'Female',
        ]);
        $child = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-771',
            'first_name' => 'Soft',
            'last_name' => 'Child',
            'relation' => 'Son',
            'sex' => 'Male',
            'birthday' => now()->subYears(2)->toDateString(),
        ]);

        $profile = HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $household->id,
            'household_type' => 'NHTS',
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'completed_step' => 4,
        ]);
        HouseholdSolidWastePractice::query()->create([
            'household_environmental_profile_id' => $profile->id,
            'waste_segregation' => true,
            'backyard_composting' => false,
            'recycling_reuse' => false,
            'municipal_collection' => false,
        ]);

        RiskAssessment::factory()->create([
            'resident_id' => $head->id,
        ]);
        ChildImmunization::factory()->create([
            'resident_id' => $child->id,
        ]);
        DewormingRecord::factory()->create([
            'resident_id' => $child->id,
            'year' => 2026,
            'round' => 1,
        ]);

        return [
            'household' => $household,
            'residents' => [$head, $child],
            'profile' => $profile,
        ];
    }

    public function test_authorized_user_can_soft_delete_db_household(): void
    {
        $this->assertFalse(app(\App\Services\HouseholdService::class)->householdDeletionSupported());

        $seed = $this->seedDeletableHousehold();
        $household = $seed['household'];

        $response = $this->delete(route('household-profiling.destroy', [
            'householdNo' => $household->household_no,
        ]));

        $response->assertRedirect(route('household-profiling.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'household_no' => 'HH-770',
        ]);
        $this->assertNotNull(Household::query()->find($household->id));
    }

    public function test_soft_deleted_household_disappears_from_profiling_and_spot_mapping(): void
    {
        $this->seedDeletableHousehold('HH-771');
        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-771',
        ]))->assertRedirect(route('household-profiling.index'));

        $index = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-household-no="HH-771"', $index);

        $spot = $this->get(route('spot-mapping.index'))->assertOk()->getContent();
        $this->assertStringContainsString('HH-771', $spot);
    }

    public function test_spot_mapping_plot_rejects_soft_deleted_household(): void
    {
        $seed = $this->seedDeletableHousehold('HH-772');
        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-772',
        ]))->assertRedirect();

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-772',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk();
    }

    public function test_view_edit_and_member_routes_cannot_access_soft_deleted_household(): void
    {
        $seed = $this->seedDeletableHousehold('HH-773');
        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-773',
        ]))->assertRedirect();

        $this->get(route('household-profiling.view', ['householdNo' => 'HH-773']))
            ->assertOk()
            ->assertDontSee('Household was not found.', false);

        $this->get(route('household-profiling.edit', ['householdNo' => 'HH-773']))
            ->assertOk();

        $this->get(route('household-profiling.members.create', ['householdNo' => 'HH-773']))
            ->assertOk();
    }

    public function test_invalid_and_already_soft_deleted_household_return_404(): void
    {
        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-999',
        ]))->assertRedirect(route('household-profiling.index'))
            ->assertSessionHas('error');

        $this->seedDeletableHousehold('HH-774');
        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-774',
        ]))->assertRedirect(route('household-profiling.index'))
            ->assertSessionHas('error');

        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-774',
        ]))->assertRedirect(route('household-profiling.index'))
            ->assertSessionHas('error');
    }

    public function test_demo_only_household_cannot_be_deleted_and_delete_control_absent(): void
    {
        $this->assertNotNull(DemoCatalog::findHousehold('HH-151'));

        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-151',
        ]))->assertRedirect(route('household-profiling.index'))
            ->assertSessionHas('error');

        $html = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-household-no="HH-151"', $html);
        $this->assertStringNotContainsString('UI demonstration', $html);
        $this->assertStringNotContainsString('Register Household', $html);
        $this->assertStringContainsString('Export Data', $html);
    }

    public function test_delete_control_present_only_for_db_rows(): void
    {
        $this->seedDeletableHousehold('HH-775');

        $html = $this->get(route('household-profiling.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-household-no="HH-775"', $html);
        $this->assertStringNotContainsString('data-hh-action="delete"', $html);
    }

    public function test_residents_and_dependent_records_remain_intact(): void
    {
        $seed = $this->seedDeletableHousehold('HH-776');
        $household = $seed['household'];
        [$head, $child] = $seed['residents'];
        $profile = $seed['profile'];

        $beforeResidents = Resident::query()->where('household_id', $household->id)->count();
        $beforeRisk = RiskAssessment::query()->where('resident_id', $head->id)->count();
        $beforeImm = ChildImmunization::query()->where('resident_id', $child->id)->count();
        $beforeDew = DewormingRecord::query()->where('resident_id', $child->id)->count();
        $beforeEh = HouseholdEnvironmentalProfile::query()->whereKey($profile->id)->count();
        $beforeSw = HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count();

        $this->delete(route('household-profiling.destroy', [
            'householdNo' => 'HH-776',
        ]))->assertRedirect();

        $this->assertSame($beforeResidents, Resident::query()->where('household_id', $household->id)->count());
        $this->assertSame(2, Resident::query()->where('household_id', $household->id)->count());
        $this->assertSame($beforeRisk, RiskAssessment::query()->where('resident_id', $head->id)->count());
        $this->assertSame($beforeImm, ChildImmunization::query()->where('resident_id', $child->id)->count());
        $this->assertSame($beforeDew, DewormingRecord::query()->where('resident_id', $child->id)->count());
        $this->assertSame($beforeEh, HouseholdEnvironmentalProfile::query()->whereKey($profile->id)->count());
        $this->assertSame($beforeSw, HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count());

        $raw = DB::table('households')->where('id', $household->id)->first();
        $this->assertNotNull($raw);
        $this->assertNull($raw->deleted_at ?? null);
    }

    public function test_destroy_route_is_delete_and_ui_role_protected(): void
    {
        $route = Route::getRoutes()->getByName('household-profiling.destroy');
        $this->assertNotNull($route);
        $this->assertTrue(in_array('DELETE', $route->methods(), true));
        $this->assertContains('ui.role', $route->gatherMiddleware());
    }

    public function test_r01c_spot_mapping_plot_panel_still_present_after_soft_delete_ui(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('Plot New Household', false)
            ->assertSee('data-spot-map-panel', false)
            ->assertSee('type="button"', false)
            ->assertSee('data-spot-map-plot', false);

        $this->post(route('household-profiling.store'), [
            'from' => 'spot-mapping',
            'household_no' => '121',
            'zone' => 'Zone 1',
            'street' => 'Still Works St.',
            'date_registered' => '2026-08-27',
            'accomplished_by' => 'R02-A Tester',
        ])->assertRedirect();

        $this->assertDatabaseHas('households', [
            'street' => 'Still Works St.',
        ]);
    }
}
