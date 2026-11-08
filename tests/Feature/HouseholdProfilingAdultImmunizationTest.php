<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\AdultImmunizationErdMode;
use App\Support\AdultImmunizationEligibility;
use App\Support\StaffRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PersistCatalogHousehold;
use Tests\TestCase;

class HouseholdProfilingAdultImmunizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
        Carbon::setTestNow(Carbon::parse('2026-09-19'));
        AdultImmunizationErdMode::resetCachedState();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedAdult(array $overrides = []): array
    {
        $household = Household::factory()->create([
            'household_no' => $overrides['household_no'] ?? 'HH-810',
            'zone' => 'Zone 1',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $overrides['member_no'] ?? 'MB-810',
            'first_name' => $overrides['first_name'] ?? 'Adult',
            'last_name' => $overrides['last_name'] ?? 'Imm',
            'sex' => $overrides['sex'] ?? 'Female',
            'birthday' => $overrides['birthday'] ?? '2000-01-01',
        ]);

        return compact('household', 'resident');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $dates = []): array
    {
        return [
            'dates' => array_merge([
                AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL => '2026-08-01',
            ], $dates),
        ];
    }

    public function test_age_17_is_rejected_on_direct_access_and_store(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedAdult([
            'birthday' => '2009-09-20',
            'sex' => 'Female',
        ]);

        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->get(route('household-profiling.members.adult-immunization', $params))
            ->assertForbidden();
        $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, DB::table('adult_immunization')->count());
        $this->assertFalse(AdultImmunizationEligibility::allows($resident->sex, $resident->birthday));
    }

    public function test_age_18_female_and_male_are_allowed(): void
    {
        foreach (['Female', 'Male'] as $index => $sex) {
            ['household' => $household, 'resident' => $resident] = $this->seedAdult([
                'household_no' => 'HH-81'.$index,
                'member_no' => 'MB-81'.$index,
                'sex' => $sex,
                'birthday' => '2008-09-19',
            ]);

            $params = [
                'householdNo' => $household->household_no,
                'memberId' => $resident->member_no,
            ];

            $this->assertTrue(AdultImmunizationEligibility::allows($sex, '2008-09-19'));
            $html = $this->get(route('household-profiling.members.adult-immunization', $params))
                ->assertOk()
                ->getContent();
            $this->assertStringContainsString('Immunization', $html);
            $this->assertStringContainsString('Pneumococcal Vaccine', $html);
            $this->assertStringContainsString('Flu Vaccine', $html);
            $this->assertStringContainsString('data-adult-imm-form', $html);
            $this->assertStringNotContainsString('data-lml-child-imm', $html);

            $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload())
                ->assertRedirect(route('household-profiling.members.adult-immunization', $params));

            $this->assertDatabaseHas('adult_immunization', [
                'resident_id' => $resident->id,
                'vaccine_type' => AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL,
                'date_given' => '2026-08-01',
            ]);
        }
    }

    public function test_both_vaccines_save_together_in_one_submission(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedAdult([
            'sex' => 'Male',
            'birthday' => '1990-02-02',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload([
            AdultImmunizationErdMode::VACCINE_FLU => '2026-07-15',
        ]))->assertRedirect();

        $this->assertDatabaseHas('adult_immunization', [
            'resident_id' => $resident->id,
            'vaccine_type' => AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL,
            'date_given' => '2026-08-01',
        ]);
        $this->assertDatabaseHas('adult_immunization', [
            'resident_id' => $resident->id,
            'vaccine_type' => AdultImmunizationErdMode::VACCINE_FLU,
            'date_given' => '2026-07-15',
        ]);

        $html = $this->get(route('household-profiling.members.adult-immunization', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('value="2026-08-01"', $html);
        $this->assertStringContainsString('value="2026-07-15"', $html);
    }

    public function test_resaving_an_already_recorded_vaccine_updates_its_date(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedAdult([
            'sex' => 'Male',
            'birthday' => '1990-02-02',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload())
            ->assertRedirect();
        $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload([
            AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL => '2026-08-20',
        ]))->assertRedirect();

        $this->assertSame(1, DB::table('adult_immunization')
            ->where('resident_id', $resident->id)
            ->where('vaccine_type', AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL)
            ->count());
        $this->assertDatabaseHas('adult_immunization', [
            'resident_id' => $resident->id,
            'vaccine_type' => AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL,
            'date_given' => '2026-08-20',
        ]);
    }

    public function test_blank_dates_leave_existing_records_untouched(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedAdult([
            'sex' => 'Male',
            'birthday' => '1990-02-02',
        ]);
        $params = [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ];

        $this->post(route('household-profiling.members.adult-immunization.store', $params), $this->validPayload())
            ->assertRedirect();

        $this->post(route('household-profiling.members.adult-immunization.store', $params), [
            'dates' => [
                AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL => '',
                AdultImmunizationErdMode::VACCINE_FLU => '',
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('adult_immunization', [
            'resident_id' => $resident->id,
            'vaccine_type' => AdultImmunizationErdMode::VACCINE_PNEUMOCOCCAL,
            'date_given' => '2026-08-01',
        ]);
        $this->assertSame(1, DB::table('adult_immunization')->where('resident_id', $resident->id)->count());
    }

    public function test_member_isolation_prevents_cross_resident_read_and_write(): void
    {
        ['household' => $householdA, 'resident' => $residentA] = $this->seedAdult([
            'household_no' => 'HH-820',
            'member_no' => 'MB-820',
        ]);
        ['household' => $householdB, 'resident' => $residentB] = $this->seedAdult([
            'household_no' => 'HH-821',
            'member_no' => 'MB-821',
            'sex' => 'Male',
        ]);

        $this->post(route('household-profiling.members.adult-immunization.store', [
            'householdNo' => $householdA->household_no,
            'memberId' => $residentA->member_no,
        ]), $this->validPayload())->assertRedirect();

        $html = $this->get(route('household-profiling.members.adult-immunization', [
            'householdNo' => $householdB->household_no,
            'memberId' => $residentB->member_no,
        ]))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="2026-08-01"', $html);
        $this->assertSame(1, DB::table('adult_immunization')->where('resident_id', $residentA->id)->count());
        $this->assertSame(0, DB::table('adult_immunization')->where('resident_id', $residentB->id)->count());
    }

    public function test_health_summary_shows_immunization_and_child_immunization_stays_separate(): void
    {
        PersistCatalogHousehold::persist('HH-151');

        $adultHtml = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-001',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-hh-member-adult-immunization', $adultHtml);
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.adult-immunization', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-001',
            ])).'"',
            $adultHtml
        );

        $childHtml = $this->get(route('household-profiling.members.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-009',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-hh-member-adult-immunization-unavailable', $childHtml);
        $this->assertStringContainsString('Child Immunization', $childHtml);
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.child-immunization', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-009',
            ])).'"',
            $childHtml
        );

        $this->assertTrue(Schema::hasTable('adult_immunization'));
        $this->assertTrue(Schema::hasTable('immunization_doses'));
        $this->assertNotSame('immunization_doses', 'adult_immunization');
    }

    public function test_invalid_vaccine_type_is_rejected(): void
    {
        ['household' => $household, 'resident' => $resident] = $this->seedAdult();

        $this->from(route('household-profiling.members.adult-immunization', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]))->post(route('household-profiling.members.adult-immunization.store', [
            'householdNo' => $household->household_no,
            'memberId' => $resident->member_no,
        ]), [
            'dates' => ['BCG' => '2026-08-01'],
        ])->assertSessionHasErrors('dates');

        $this->assertSame(0, DB::table('adult_immunization')->count());
    }
}
