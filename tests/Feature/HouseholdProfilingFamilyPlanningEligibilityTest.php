<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\FamilyPlanningEligibility;
use App\Support\FamilyPlanningErdMode;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

class HouseholdProfilingFamilyPlanningEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        Carbon::setTestNow(Carbon::parse('2026-09-19'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedResident(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-910',
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-910',
            'first_name' => $overrides['first_name'] ?? 'Fp',
            'last_name' => $overrides['last_name'] ?? 'Gate',
            'sex' => $overrides['sex'] ?? 'Female',
            'birthday' => $overrides['birthday'] ?? '2010-09-19',
        ]);

        return compact('household', 'resident');
    }

    public function test_male_does_not_see_family_planning_on_health_summary(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $html = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-hh-member-family-planning', $html);
        $this->assertStringNotContainsString(
            'href="'.e(route('household-profiling.members.family-planning.index', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])).'"',
            $html
        );
    }

    public function test_male_direct_access_is_blocked(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedResident([
            'sex' => 'Male',
            'birthday' => '1990-01-01',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->assertFalse(FamilyPlanningEligibility::allows('Male', '1990-01-01'));
        $this->get(route('household-profiling.members.family-planning.index', $params))->assertForbidden();
        $this->get(route('household-profiling.members.family-planning.create', $params))->assertForbidden();
        $this->post(route('household-profiling.members.family-planning.store', $params), [
            'visited_at' => '2026-08-01',
            'remarks' => 'Blocked',
        ])->assertForbidden();
    }

    public function test_female_age_9_is_blocked_and_age_10_is_allowed(): void
    {
        ['household' => $youngHh, 'resident' => $young] = $this->seedResident([
            'household_no' => 'HH-911',
            'member_no' => 'MB-911',
            'sex' => 'Female',
            'birthday' => '2016-09-20',
        ]);
        ['household' => $eligibleHh, 'resident' => $eligible] = $this->seedResident([
            'household_no' => 'HH-912',
            'member_no' => 'MB-912',
            'sex' => 'Female',
            'birthday' => '2016-09-19',
        ]);

        $this->assertFalse(FamilyPlanningEligibility::allows('Female', '2016-09-20'));
        $this->assertTrue(FamilyPlanningEligibility::allows('Female', '2016-09-19'));

        $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $youngHh->household_no,
            'memberId' => $young->member_no,
        ]))->assertForbidden();

        $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $eligibleHh->household_no,
            'memberId' => $eligible->member_no,
        ]))->assertOk();
    }

    public function test_female_older_than_10_can_add_and_persist_commodity(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedResident([
            'sex' => 'Female',
            'birthday' => '1995-03-01',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post(route('household-profiling.members.family-planning.store', $params), [
            'visited_at' => '2026-08-02',
            'remarks' => 'Commodity visit',
            'commodities' => [
                ['name' => 'Pills', 'quantity' => 4],
            ],
        ])->assertRedirect(route('household-profiling.members.family-planning.index', $params));

        $visit = DB::table('family_planning_visits')->where('resident_id', $resident->id)->first();
        $this->assertNotNull($visit);
        $this->assertSame('2026-08-02', substr((string) $visit->visited_at, 0, 10));
        $commodities = is_string($visit->commodities)
            ? json_decode((string) $visit->commodities, true)
            : $visit->commodities;
        $this->assertSame([['name' => 'Pills', 'quantity' => 4]], $commodities);

        $html = $this->get(route('household-profiling.members.family-planning.index', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Pills', $html);
    }

    public function test_invalid_commodity_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedResident([
            'sex' => 'Female',
            'birthday' => '1992-01-01',
        ]);

        $this->from(route('household-profiling.members.family-planning.create', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post(route('household-profiling.members.family-planning.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'visited_at' => '2026-08-03',
            'commodities' => [
                ['name' => 'Unknown Pill', 'quantity' => 1],
            ],
        ])->assertSessionHasErrors('commodities.0.name');

        $this->assertSame(0, DB::table('family_planning_visits')->count());
    }

    public function test_erd_commodity_persists_on_fp_commodities_given_and_stays_isolated(): void
    {
        Schema::dropIfExists('family_planning_visits');
        Schema::dropIfExists('fp_commodities_given');
        Schema::dropIfExists('family_planning');
        Schema::create('family_planning', function ($table): void {
            $table->id('fp_id');
            $table->unsignedBigInteger('resident_id');
            $table->date('visitation_date');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        Schema::create('fp_commodities_given', function ($table): void {
            $table->id('commodity_given_id');
            $table->unsignedBigInteger('fp_id');
            $table->string('commodity_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
        FamilyPlanningErdMode::resetCachedState();

        ['household' => $householdA, 'resident' => $residentA] = $this->seedResident([
            'household_no' => 'HH-930',
            'member_no' => 'MB-930',
            'sex' => 'Female',
            'birthday' => '1988-04-04',
        ]);
        ['household' => $householdB, 'resident' => $residentB] = $this->seedResident([
            'household_no' => 'HH-931',
            'member_no' => 'MB-931',
            'sex' => 'Female',
            'birthday' => '1987-04-04',
        ]);

        $this->post(route('household-profiling.members.family-planning.store', [
            'householdNo' => $householdA->household_no,
            'memberId' => $residentA->member_no,
        ]), [
            'visited_at' => '2026-08-04',
            'remarks' => 'ERD commodity',
            'commodities' => [
                ['name' => 'IUD', 'quantity' => 1],
            ],
        ])->assertRedirect();

        $this->assertSame(1, DB::table('family_planning')->where('resident_id', $residentA->id)->count());
        $fpId = (int) DB::table('family_planning')->where('resident_id', $residentA->id)->value('fp_id');
        $this->assertDatabaseHas('fp_commodities_given', [
            'fp_id' => $fpId,
            'commodity_name' => 'IUD',
            'quantity' => 1,
        ]);
        $this->assertSame(0, DB::table('family_planning')->where('resident_id', $residentB->id)->count());
        $this->assertSame(0, DB::table('fp_commodities_given')->where('fp_id', '!=', $fpId)->count());

        $html = $this->get(route('household-profiling.members.family-planning.index', [
            'householdNo' => $householdB->household_no,
            'memberId' => $residentB->member_no,
        ]))->assertOk()->getContent();
        $this->assertStringNotContainsString('IUD', $html);
    }
}
