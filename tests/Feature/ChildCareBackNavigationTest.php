<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\HealthRecordsChildCare;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

/**
 * FR-32 — resident Child Care Back uses named Member View / Child Immunization routes.
 */
class ChildCareBackNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        PersistCatalogHousehold::persist('HH-151');
        PersistCatalogHousehold::persist('HH-152');
    }

    public function test_child_immunization_back_is_isolated_to_the_same_member(): void
    {
        $a = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];
        $b = ['householdNo' => 'HH-152', 'memberId' => 'MB-001'];

        $html = $this->get(route('household-profiling.members.child-immunization', $a))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $a)).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('household-profiling.members.show', $b)).'"', $html);
        $this->assertStringNotContainsString('javascript:history.back()', $html);
        $this->assertStringNotContainsString('return_to=', $html);
    }

    public function test_school_based_immunization_back_is_isolated_to_the_same_member(): void
    {
        $a = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];
        $b = ['householdNo' => 'HH-152', 'memberId' => 'MB-001'];

        $html = $this->get(route('household-profiling.members.school-based-immunization', $a))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $a)).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('household-profiling.members.show', $b)).'"', $html);
    }

    public function test_child_nutrition_back_is_isolated_to_the_same_member(): void
    {
        $a = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];
        $b = ['householdNo' => 'HH-152', 'memberId' => 'MB-001'];

        $html = $this->get(route('household-profiling.members.child-nutrition', $a))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $a)).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('household-profiling.members.show', $b)).'"', $html);
    }

    public function test_hp_deworming_back_uses_member_view_not_health_records_monitoring(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-001'];
        $html = $this->get(route('household-profiling.members.deworming', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $params)).'"', $html);
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
        $this->assertStringContainsString('>Back</span>', $html);
        $this->assertStringNotContainsString('javascript:history.back()', $html);
    }

    public function test_hp_deworming_missing_child_still_backs_to_member_view(): void
    {
        $params = ['householdNo' => 'HH-151', 'memberId' => 'MB-999'];
        $html = $this->get(route('household-profiling.members.deworming', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $params)).'"', $html);
        $this->assertStringNotContainsString(
            'href="'.e(route('health-records.child-care.deworming')).'"',
            $html
        );
    }

    public function test_ineligible_sbi_still_backs_to_member_view(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-1032',
            'zone' => 'Zone 1',
        ]);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-1032',
            'birthday' => now()->subMonths(HealthRecordsChildCare::MAX_AGE_MONTHS)->format('Y-m-d'),
            'first_name' => 'Floor',
            'last_name' => 'Child',
            'relation' => 'Daughter',
            'sex' => 'Female',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $html = $this->get(route('household-profiling.members.school-based-immunization', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-sbi-ineligible', $html);
        $this->assertStringContainsString('href="'.e(route('household-profiling.members.show', $params)).'"', $html);
        $this->assertStringContainsString('>Back</span>', $html);
    }
}
