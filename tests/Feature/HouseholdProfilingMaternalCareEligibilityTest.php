<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Support\MaternalCareEligibility;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

class HouseholdProfilingMaternalCareEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        PersistCatalogHousehold::persist('HH-151');
    }

    public function test_female_member_can_open_maternal_care(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-002'];

        $this->get(route('household-profiling.members.maternal-care.index', $params))
            ->assertOk()
            ->assertSee('data-lml-mc-mode="landing"', false);
    }

    public function test_female_member_view_shows_maternal_care_action(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-002'];

        $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->assertSee('data-hh-member-maternal-care', false)
            ->assertSee(
                'href="'.e(route('household-profiling.members.maternal-care.index', $params)).'"',
                false
            );
    }

    public function test_male_member_view_hides_maternal_care_action(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hh-member-maternal-care', $html);
        $this->assertStringNotContainsString(
            route('household-profiling.members.maternal-care.index', $params),
            $html
        );
    }

    public function test_male_direct_get_is_rejected(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];

        $this->get(route('household-profiling.members.maternal-care.index', $params))
            ->assertForbidden();
        $this->get(route('household-profiling.members.maternal-care.register', $params))
            ->assertForbidden();
        $this->get(route('household-profiling.members.maternal-care.history', $params))
            ->assertForbidden();
    }

    public function test_male_post_is_rejected_and_does_not_write(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-982',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-982',
            'sex' => 'Male',
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
        ]);

        $before = $this->maternalRowCount();

        $this->post(route('household-profiling.members.maternal-care.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'lmp' => '2026-01-15',
            'gravida' => 1,
            'parity' => 0,
            'edd' => '2026-10-22',
            'weight' => 60,
            'height' => 165,
            'bmi' => 22,
            'blood_pressure' => '110/70',
        ])->assertForbidden();

        $this->assertSame($before, $this->maternalRowCount());
        $this->assertSame(0, MaternalPregnancy::query()->where('resident_id', $resident->id)->count());
    }

    public function test_unknown_sex_is_rejected(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-983',
            'zone' => 'Zone 2',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-983',
            'sex' => 'Unknown',
            'first_name' => 'Alex',
            'last_name' => 'Santos',
        ]);

        $this->assertFalse(MaternalCareEligibility::allows($resident->sex));

        $this->get(route('household-profiling.members.maternal-care.index', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->assertForbidden();
    }

    private function maternalRowCount(): int
    {
        $count = 0;

        if (Schema::hasTable('maternal_pregnancies')) {
            $count += (int) DB::table('maternal_pregnancies')->count();
        }

        if (Schema::hasTable('maternal_care')) {
            $count += (int) DB::table('maternal_care')->count();
        }

        return $count;
    }
}
