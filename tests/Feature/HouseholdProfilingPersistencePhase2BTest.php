<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\HouseholdEnvironmentalProfile;
use App\Models\HouseholdSolidWastePractice;
use App\Models\Resident;
use App\Services\HouseholdEnvironmentalProfileService;
use App\Support\DemoCatalog;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * DB-17 Phase 2B — household environmental / amenities MySQL persistence.
 */
class HouseholdProfilingPersistencePhase2BTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function step1Payload(array $overrides = []): array
    {
        return array_merge([
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function step2Payload(array $overrides = []): array
    {
        return array_merge([
            'microbiological_test_date' => '2026-07-20',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-22',
            'physicochemical_result' => 'failed',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function step3Payload(array $overrides = []): array
    {
        return array_merge([
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function step4Payload(array $overrides = []): array
    {
        return array_merge([
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullAmenitiesPayload(array $overrides = []): array
    {
        return array_merge(
            $this->step1Payload(),
            $this->step2Payload(),
            $this->step3Payload(),
            $this->step4Payload(),
            $overrides
        );
    }

    private function dbOnlyHousehold(string $householdNo = 'HH-888'): Household
    {
        $this->assertNull(DemoCatalog::findHousehold($householdNo));

        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 4',
            'street' => 'Db Only St.',
        ]);
    }

    public function test_db_only_household_opens_environmental_workflow(): void
    {
        $household = $this->dbOnlyHousehold();

        $this->get(route('household-profiling.amenities.show', ['householdNo' => $household->household_no]))
            ->assertOk()
            ->assertSee('Household Amenities Details', false)
            ->assertDontSee('Household not found', false);

        $this->get(route('household-profiling.amenities.edit', ['householdNo' => $household->household_no]))
            ->assertOk()
            ->assertSee('Edit household amenities', false);
    }

    public function test_step1_water_supply_persists_and_survives_reload(): void
    {
        $household = $this->dbOnlyHousehold('HH-881');
        $service = app(HouseholdEnvironmentalProfileService::class);

        $service->saveStep1($household, $this->step1Payload([
            'water_supply_status' => 'level_ii',
        ]));

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'yes',
            'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH,
            'completed_step' => 1,
        ]);

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-881']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_ii"[^>]*aria-current="true"/', $html);
    }

    public function test_step1_update_replaces_household_owned_row(): void
    {
        $household = $this->dbOnlyHousehold('HH-882');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveStep1($household, $this->step1Payload(['water_supply_status' => 'level_i']));
        $service->saveStep1($household, $this->step1Payload([
            'water_supply_status' => 'others',
            'specify_water_source' => 'Spring box',
        ]));

        $this->assertSame(1, HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->count());
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'others',
            'specify_water_source' => 'Spring box',
            'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT,
        ]);
    }

    public function test_step2_validation_persists_and_survives_reload(): void
    {
        $household = $this->dbOnlyHousehold('HH-883');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveStep1($household, $this->step1Payload());
        $service->saveStep2($household, $this->step2Payload());

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'microbiological_result' => 'passed',
            'physicochemical_result' => 'failed',
            'completed_step' => 2,
        ]);

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-883']))
            ->assertOk()
            ->assertSee('07/20/2026', false)
            ->assertSee('Passed', false);
    }

    public function test_step3_sanitation_persists_and_survives_reload(): void
    {
        $household = $this->dbOnlyHousehold('HH-884');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveStep1($household, $this->step1Payload());
        $service->saveStep2($household, $this->step2Payload());
        $service->saveStep3($household, $this->step3Payload());

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_SANITARY,
            'management_status' => DemoHouseholdWaterSupply::MANAGEMENT_STATUS_SAFELY_MANAGED,
            'completed_step' => 3,
        ]);

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-884']))
            ->assertOk()
            ->assertSee('Safely Managed', false);
    }

    public function test_step4_solid_waste_persists_and_survives_reload(): void
    {
        $household = $this->dbOnlyHousehold('HH-885');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveStep1($household, $this->step1Payload());
        $service->saveStep2($household, $this->step2Payload());
        $service->saveStep3($household, $this->step3Payload());
        $service->saveStep4($household, $this->step4Payload());

        $profile = HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertDatabaseHas('household_solid_waste_practices', [
            'household_environmental_profile_id' => $profile->id,
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 0,
            'municipal_collection' => 1,
        ]);

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-885']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Good Practice', $html);
    }

    public function test_complete_profile_survives_new_session_via_http_update(): void
    {
        $household = $this->dbOnlyHousehold('HH-886');

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-886']),
            $this->fullAmenitiesPayload([
                'water_supply_status' => 'level_iii',
                'specify_water_source' => null,
            ])
        )->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-886']));

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'level_iii',
            'completed_step' => 4,
        ]);

        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-886']))
            ->assertOk()
            ->assertSee('Level III', false);
    }

    public function test_household_a_cannot_read_or_write_household_b_environmental_data(): void
    {
        $householdA = $this->dbOnlyHousehold('HH-890');
        $householdB = $this->dbOnlyHousehold('HH-891');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveAll($householdA, $this->fullAmenitiesPayload([
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
        ]));
        $service->saveAll($householdB, $this->fullAmenitiesPayload([
            'water_supply_status' => 'others',
            'specify_water_source' => 'Secret well',
        ]));

        $showA = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-890']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Secret well', $showA);
        $this->assertMatchesRegularExpression('/data-water-level="level_i"[^>]*aria-current="true"/', $showA);

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-890']),
            $this->fullAmenitiesPayload([
                'household_id' => $householdB->id,
                'household_no' => 'HH-891',
                'water_supply_status' => 'level_ii',
                'specify_water_source' => null,
            ])
        )->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-890']));

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $householdA->id,
            'water_supply_status' => 'level_ii',
        ]);
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $householdB->id,
            'water_supply_status' => 'others',
            'specify_water_source' => 'Secret well',
        ]);
    }

    public function test_forged_household_id_is_ignored(): void
    {
        $household = $this->dbOnlyHousehold('HH-892');
        $other = $this->dbOnlyHousehold('HH-893');

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-892']),
            $this->fullAmenitiesPayload([
                'id' => 99999,
                'household_id' => $other->id,
                'water_supply_status' => 'level_i',
            ])
        )->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'level_i',
        ]);
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $other->id,
        ]);
    }

    public function test_one_profile_per_household_unique_constraint(): void
    {
        $household = $this->dbOnlyHousehold('HH-894');
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $household->id,
            'completed_step' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        HouseholdEnvironmentalProfile::query()->create([
            'household_id' => $household->id,
            'completed_step' => 0,
        ]);
    }

    public function test_solid_waste_practices_do_not_create_duplicate_rows(): void
    {
        $household = $this->dbOnlyHousehold('HH-895');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveAll($household, $this->fullAmenitiesPayload([
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ]));
        $service->saveStep4($household, [
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
            ],
        ]);

        $profile = HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame(1, HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count());
        $this->assertDatabaseHas('household_solid_waste_practices', [
            'household_environmental_profile_id' => $profile->id,
            'waste_segregation' => 0,
            'backyard_composting' => 1,
            'recycling_reuse' => 0,
            'municipal_collection' => 0,
        ]);
    }

    public function test_transaction_rollback_prevents_partial_multi_table_write(): void
    {
        $household = $this->dbOnlyHousehold('HH-896');
        $service = app(HouseholdEnvironmentalProfileService::class);
        $service->saveStep1($household, $this->step1Payload());

        HouseholdSolidWastePractice::creating(function (): void {
            throw new \RuntimeException('Forced solid-waste failure');
        });

        try {
            $service->saveStep4($household, $this->step4Payload());
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Forced solid-waste failure', $e->getMessage());
        }

        $profile = HouseholdEnvironmentalProfile::query()->where('household_id', $household->id)->firstOrFail();
        $this->assertSame(1, (int) $profile->completed_step);
        $this->assertNull($profile->solid_waste_status);
        $this->assertSame(0, HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count());
    }

    public function test_democatalog_not_required_for_production_writes(): void
    {
        $household = $this->dbOnlyHousehold('HH-897');
        $this->assertNull(DemoCatalog::findHousehold('HH-897'));

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-897']),
            $this->fullAmenitiesPayload()
        )->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
        ]);
    }

    public function test_member_persistence_still_works_with_environmental_tables(): void
    {
        $household = $this->dbOnlyHousehold('HH-898');
        app(HouseholdEnvironmentalProfileService::class)->saveAll($household, $this->fullAmenitiesPayload());

        $this->post(route('household-profiling.members.store', ['householdNo' => 'HH-898']), [
            'last_name' => 'Reyes',
            'first_name' => 'Lito',
            'middle_name' => null,
            'relation' => 'Head',
            'birthday' => '1985-05-05',
            'sex' => 'Male',
            'relationship_status' => 'Married',
            'occupation' => 'Farmer',
            'monthly_income' => 'Below 5,000',
            'religion' => 'Roman Catholic',
            'education' => 'High School Graduate',
            'fp_user' => 'No',
            'philhealth' => null,
            'disability' => ['none'],
            'medical_history' => ['none'],
        ])->assertRedirect();

        $this->assertSame(1, $household->residents()->count());
        $this->assertSame(1, $household->environmentalProfile()->count());
    }

    public function test_household_view_cards_consume_db_profile(): void
    {
        $household = $this->dbOnlyHousehold('HH-899');
        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-899',
            'relation' => 'Head',
            'first_name' => 'View',
            'last_name' => 'Head',
        ]);
        app(HouseholdEnvironmentalProfileService::class)->saveAll($household, $this->fullAmenitiesPayload([
            'water_supply_status' => 'level_ii',
        ]));

        $html = $this->get(route('household-profiling.view', ['householdNo' => 'HH-899']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Level II', $html);
        $this->assertStringContainsString('With Basic Safe Water', $html);
        $this->assertStringNotContainsString('Not recorded', $html);
    }
}
