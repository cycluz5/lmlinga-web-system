<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\HouseholdProfilingWriteGuard;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdAmenitiesErdWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdAmenitiesTables();
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
    }

    public function test_erd_amenities_show_and_edit_load_existing_sanitation(): void
    {
        $this->seedHouseholdWithSanitation('HH-005');

        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']))
            ->assertOk()
            ->assertSee('Household Amenities Details', false)
            ->assertSee('NHTS', false);

        $edit = $this->get(route('household-profiling.amenities.edit', ['householdNo' => 'HH-005']));
        $edit->assertOk();
        $edit->assertSee('Edit Household Amenities Details', false);
        $html = $edit->getContent();
        $this->assertMatchesRegularExpression(
            '/name="water_availability"\s+value="yes"[^>]*checked|value="yes"[^>]*name="water_availability"[^>]*checked/',
            $html
        );
        $this->assertStringContainsString('pour_flush_with_septic_tank', $html);
    }

    public function test_erd_amenities_put_persists_all_four_sections_and_show_reloads(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005', [], [
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
        ]);

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $this->assertFalse(Schema::hasColumn('environmental_sanitation', 'household_type'));

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-005']),
            $this->validAmenitiesPayload('HH-005', [
                'water_supply_status' => 'level_i',
                'water_source_location' => 'no',
                'water_availability' => 'no',
                'microbiological_test_date' => '2026-08-01',
                'microbiological_result' => 'failed',
                'physicochemical_test_date' => '2026-08-02',
                'physicochemical_result' => 'passed',
                'toilet_type' => 'open_pit_latrine',
                'open_defecation_practiced' => 'yes',
                'shared_toilet' => 'yes',
                'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_OFF_SITE,
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
                    DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
                ],
            ])
        )->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']));

        $this->assertFalse(Schema::hasTable('household_environmental_profiles'));
        $this->assertSame(1, DB::table('environmental_sanitation')->count());

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();
        $this->assertNotNull($row);
        $this->assertFalse(isset($row->household_type));
        $this->assertSame('Level I', (string) $row->water_supply_status);
        $this->assertSame(0, (int) $row->water_availability);
        $this->assertSame('Community faucet', (string) $row->water_source_location);
        $this->assertSame('2026-08-01', (string) $row->microbiological_validation_date);
        $this->assertSame('Failed', (string) $row->microbio_result);
        $this->assertSame('2026-08-02', (string) $row->physico_chem_test_date);
        $this->assertSame('Passed', (string) $row->physico_chem_result);
        $this->assertSame('open_pit_latrine', (string) $row->toilet_type);
        $this->assertSame(1, (int) $row->open_defecation_place);
        $this->assertSame(1, (int) $row->shared_toilet);
        $this->assertSame('Off-site Disposed', (string) $row->sewage_disposal_method);

        $waste = DB::table('waste_management_practices')
            ->where('env_assessment_id', $row->env_assessment_id)
            ->first();
        $this->assertNotNull($waste);
        $this->assertSame(0, (int) $waste->waste_segregation);
        $this->assertSame(1, (int) $waste->backyard_composting);
        $this->assertSame(0, (int) $waste->recycling_reuse);
        $this->assertSame(1, (int) $waste->collected_by_municipality);

        $show = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']));
        $show->assertOk();
        $show->assertSee('Household amenities details saved successfully.', false);
        $html = $show->getContent();
        $this->assertMatchesRegularExpression(
            '/lml-amenities__level-card is-selected[^>]*data-water-level="level_i"|data-water-level="level_i"[^>]*class="[^"]*is-selected/',
            $html
        );
        $this->assertStringContainsString('08/01/2026', $html);
        $this->assertStringContainsString('Failed', $html);
        $this->assertStringContainsString('Not Safely Managed', $html);
        $this->assertStringContainsString('Backyard Composting', $html);
    }

    public function test_erd_amenities_put_accepts_blank_sewage_and_persists_null(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $this->assertSame(
            'On-site Disposed',
            (string) DB::table('environmental_sanitation')
                ->where('household_id', $household->getKey())
                ->value('sewage_disposal_method')
        );

        $payload = $this->validAmenitiesPayload('HH-005');
        unset($payload['sewage_disposal_method']);

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-005']),
            $payload
        )->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']))
            ->assertSessionDoesntHaveErrors();

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();

        $this->assertNull($row->sewage_disposal_method);
        $this->assertSame('pour_flush_with_septic_tank', (string) $row->toilet_type);
        $this->assertSame(0, (int) $row->shared_toilet);
    }

    public function test_erd_amenities_put_ignores_forged_ids_type_and_derived_statuses(): void
    {
        $household = $this->seedHouseholdWithSanitation('HH-005');
        $other = $this->seedHouseholdWithSanitation('HH-006', [
            'water_supply_status' => 'Level III',
        ], [
            'waste_segregation' => 0,
            'backyard_composting' => 1,
            'recycling_reuse' => 0,
            'collected_by_municipality' => 0,
        ]);

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-005']), array_merge(
            $this->validAmenitiesPayload('HH-005', [
                'water_supply_status' => 'level_iii',
                'water_availability' => 'no',
            ]),
            [
                'household_no' => 'HH-006',
                'id' => 99999,
                'household_id' => $other->getKey(),
                'household_environmental_profile_id' => 88888,
                'profile_id' => 77777,
                'household_type' => 'Non-HHTS',
                'completed_step' => 0,
                'basic_safe_water_status' => 'without_basic_safe_water',
                'toilet_status' => 'unsanitary',
                'management_status' => 'safely_managed',
                'solid_waste_status' => 'not_yet_determined',
            ]
        ))->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']));

        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();
        $this->assertSame('Level III', (string) $row->water_supply_status);
        $this->assertFalse(isset($row->household_type));
        $this->assertSame(
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
            (string) $household->fresh()->household_type
        );

        $otherRow = DB::table('environmental_sanitation')
            ->where('household_id', $other->getKey())
            ->first();
        $this->assertSame('Level III', (string) $otherRow->water_supply_status);
        $this->assertSame(1, (int) DB::table('waste_management_practices')
            ->where('env_assessment_id', $otherRow->env_assessment_id)
            ->value('backyard_composting'));
    }

    public function test_erd_amenities_ses_reads_canonical_household_row_type(): void
    {
        $this->seedHouseholdWithSanitation('HH-005');
        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-005']))
            ->assertOk()
            ->assertSee('>NHTS</span>', false);

        $nonNhts = $this->seedHouseholdWithSanitation('HH-007', [], null, DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS);
        $this->assertSame(
            DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NON_NHTS,
            (string) $nonNhts->household_type
        );
        $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-007']))
            ->assertOk()
            ->assertSee('>Non-NHTS</span>', false)
            ->assertDontSee('>NHTS</span>', false);
    }

    public function test_erd_amenities_complete_payload_inserts_when_sanitation_row_missing(): void
    {
        $household = $this->seedHouseholdWithoutSanitation('HH-008');
        $this->assertSame(0, DB::table('environmental_sanitation')->count());

        $this->put(
            route('household-profiling.amenities.update', ['householdNo' => 'HH-008']),
            $this->validAmenitiesPayload('HH-008', [
                'water_supply_status' => 'level_ii',
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                'microbiological_test_date' => '2026-03-01',
                'microbiological_result' => 'passed',
                'physicochemical_test_date' => '2026-03-02',
                'physicochemical_result' => 'failed',
                'toilet_type' => 'pour_flush_with_septic_tank',
                'open_defecation_practiced' => 'no',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                ],
            ])
        )->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-008']));

        $this->assertSame(1, DB::table('environmental_sanitation')->count());
        $row = DB::table('environmental_sanitation')
            ->where('household_id', $household->getKey())
            ->first();
        $this->assertSame('Level II', (string) $row->water_supply_status);
        $this->assertSame('yes', (string) $row->water_source_location);
        $this->assertSame(1, (int) $row->water_availability);
        $this->assertSame('2026-03-01', (string) $row->microbiological_validation_date);
        $this->assertSame('Passed', (string) $row->microbio_result);
        $this->assertSame('pour_flush_with_septic_tank', (string) $row->toilet_type);
        $this->assertFalse(isset($row->household_type));

        $waste = DB::table('waste_management_practices')
            ->where('env_assessment_id', $row->env_assessment_id)
            ->first();
        $this->assertSame(1, (int) $waste->waste_segregation);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-008']))
            ->assertOk()
            ->getContent();
        $this->assertMatchesRegularExpression(
            '/data-water-level="level_ii"[^>]*class="[^"]*is-selected|lml-amenities__level-card is-selected[^>]*data-water-level="level_ii"/',
            $html
        );
    }

    public function test_erd_amenities_incomplete_missing_row_fails_without_creating_partial_record(): void
    {
        $household = $this->seedHouseholdWithoutSanitation('HH-009');

        $this->from(route('household-profiling.amenities.edit', ['householdNo' => 'HH-009']))
            ->put(
                route('household-profiling.amenities.update', ['householdNo' => 'HH-009']),
                $this->validAmenitiesPayload('HH-009', [
                    'microbiological_test_date' => '',
                    'microbiological_result' => '',
                    'physicochemical_test_date' => '',
                    'physicochemical_result' => '',
                ])
            )
            ->assertRedirect(route('household-profiling.amenities.edit', ['householdNo' => 'HH-009']))
            ->assertSessionHasErrors('amenities');

        $this->assertSame(0, DB::table('environmental_sanitation')->where('household_id', $household->getKey())->count());
        $this->assertSame(0, DB::table('waste_management_practices')->count());
    }

    public function test_erd_amenities_put_rejected_when_waste_management_table_absent(): void
    {
        Schema::dropIfExists('waste_management_practices');
        EnvironmentalSanitationErdMode::resetCachedState();
        $this->assertTrue(HouseholdProfilingWriteGuard::isAmenitiesWriteUnsupported());

        $this->seedHouseholdWithSanitation('HH-005');

        $this->from(route('household-profiling.amenities.edit', ['householdNo' => 'HH-005']))
            ->put(
                route('household-profiling.amenities.update', ['householdNo' => 'HH-005']),
                $this->validAmenitiesPayload('HH-005')
            )
            ->assertRedirect(route('household-profiling.amenities.edit', ['householdNo' => 'HH-005']))
            ->assertSessionHasErrors('amenities');
    }

    private function provisionErdAmenitiesTables(): void
    {
        Schema::dropIfExists('household_solid_waste_practices');
        Schema::dropIfExists('household_environmental_profiles');
        Schema::dropIfExists('waste_management_practices');
        Schema::dropIfExists('environmental_sanitation');

        Schema::create('environmental_sanitation', function ($table): void {
            $table->id('env_assessment_id');
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('water_supply_status');
            $table->string('water_source_location');
            $table->unsignedTinyInteger('water_availability');
            $table->date('microbiological_validation_date');
            $table->string('microbio_result')->nullable();
            $table->date('physico_chem_test_date');
            $table->string('physico_chem_result')->nullable();
            $table->string('toilet_type')->nullable();
            $table->unsignedTinyInteger('open_defecation_place')->nullable();
            $table->unsignedTinyInteger('shared_toilet')->nullable();
            $table->string('sewage_disposal_method')->nullable();
            $table->timestamps();
        });

        Schema::create('waste_management_practices', function ($table): void {
            $table->id('waste_management_practices_id');
            $table->unsignedBigInteger('env_assessment_id')->unique();
            $table->unsignedTinyInteger('waste_segregation')->default(0);
            $table->unsignedTinyInteger('backyard_composting')->default(0);
            $table->unsignedTinyInteger('recycling_reuse')->default(0);
            $table->unsignedTinyInteger('collected_by_municipality')->default(0);
            $table->timestamps();
        });

        if (! Schema::hasColumn('households', 'household_type')) {
            Schema::table('households', function ($table): void {
                $table->string('household_type', 50)->nullable();
            });
        }

        EnvironmentalSanitationErdMode::resetCachedState();
    }

    /**
     * @param  array<string, mixed>  $sanitationOverrides
     * @param  array<string, mixed>|null  $wasteOverrides
     */
    private function seedHouseholdWithSanitation(
        string $householdNo,
        array $sanitationOverrides = [],
        ?array $wasteOverrides = null,
        string $householdType = DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
    ): Household {
        $household = $this->seedHouseholdWithoutSanitation($householdNo, $householdType);

        $envAssessmentId = DB::table('environmental_sanitation')->insertGetId(array_merge([
            'household_id' => $household->getKey(),
            'user_id' => 18,
            'water_supply_status' => 'Level II',
            'water_source_location' => 'Community faucet',
            'water_availability' => 1,
            'microbiological_validation_date' => '2026-06-01',
            'microbio_result' => 'Passed',
            'physico_chem_test_date' => '2026-06-01',
            'physico_chem_result' => 'Passed',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_place' => 0,
            'shared_toilet' => 0,
            'sewage_disposal_method' => 'On-site Disposed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $sanitationOverrides), 'env_assessment_id');

        if ($wasteOverrides !== null) {
            DB::table('waste_management_practices')->insert(array_merge([
                'env_assessment_id' => $envAssessmentId,
                'waste_segregation' => 0,
                'backyard_composting' => 0,
                'recycling_reuse' => 0,
                'collected_by_municipality' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ], $wasteOverrides));
        }

        return $household->fresh();
    }

    private function seedHouseholdWithoutSanitation(
        string $householdNo,
        string $householdType = DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
    ): Household {
        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 5',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => 13.3811,
            'longitude' => 123.4306,
        ]);
        $household->forceFill(['household_type' => $householdType])->save();

        return $household->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validAmenitiesPayload(string $householdNo, array $overrides = []): array
    {
        return array_merge([
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '2026-07-20',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-22',
            'physicochemical_result' => 'failed',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => DemoHouseholdWaterSupply::SEWAGE_ON_SITE,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ], $overrides);
    }
}
