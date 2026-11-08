<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdWaterSupplyStep4Test extends TestCase
{
    use RefreshDatabase;

    private function seedThroughStep3(string $householdNo = 'HH-801'): string
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        $payload = [
            'household_no' => $householdNo,
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-step4',
        ];

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $payload);
        $issue->assertOk();

        $token = (string) $issue->json('handoff_token');

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => $token,
        ]))->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.step3.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ])->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]));

        return $householdNo;
    }

    public function test_at_least_one_practice_is_required(): void
    {
        $householdNo = $this->seedThroughStep3('HH-802');

        $response = $this->from(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors([
            'solid_waste_practices' => 'Please select at least one solid waste management practice.',
        ]);
    }

    public function test_invalid_practice_value_is_rejected(): void
    {
        $householdNo = $this->seedThroughStep3('HH-803');

        $response = $this->from(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => ['waste_segregation', 'bad_value'],
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors(['solid_waste_practices.1']);
    }

    public function test_multiple_practices_are_persisted_and_marked_good_practice(): void
    {
        $householdNo = $this->seedThroughStep3('HH-804');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
        ])->assertRedirect(route('spot-mapping.index'));

        $record = DemoHouseholdWaterSupply::find($householdNo);

        $this->assertSame([
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
        ], $record['solid_waste_practices'] ?? null);
        $this->assertSame('good_practice', $record['solid_waste_status'] ?? null);
        $this->assertSame(4, (int) ($record['step'] ?? 0));
        $this->assertTrue(DemoHouseholdWaterSupply::hasCompletedStep4($householdNo));
    }

    public function test_saved_practices_reload_on_step4_page(): void
    {
        $householdNo = $this->seedThroughStep3('HH-805');

        DemoHouseholdWaterSupply::saveStep4($householdNo, [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
            ],
        ]);

        $page = $this->get(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]));

        $page->assertOk();
        $page->assertSee('Solid Waste Management', false);
        $page->assertSee('Waste Management Practices', false);
        $page->assertSee('value="backyard_composting"', false);
        $page->assertSee('GOOD PRACTICE', false);
    }

    public function test_single_valid_selection_persists_as_good_practice(): void
    {
        $householdNo = $this->seedThroughStep3('HH-806');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertRedirect(route('spot-mapping.index'));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame([
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
        ], $record['solid_waste_practices'] ?? null);
        $this->assertSame('good_practice', $record['solid_waste_status'] ?? null);
    }

    public function test_all_four_selections_persist_as_good_practice(): void
    {
        $householdNo = $this->seedThroughStep3('HH-807');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
        ])->assertRedirect(route('spot-mapping.index'));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame(DemoHouseholdWaterSupply::solidWastePracticeValues(), $record['solid_waste_practices'] ?? null);
        $this->assertSame('good_practice', $record['solid_waste_status'] ?? null);
    }

    public function test_duplicate_practice_submissions_are_normalized_to_one_value(): void
    {
        $householdNo = $this->seedThroughStep3('HH-808');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertRedirect(route('spot-mapping.index'));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame([
            DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
        ], $record['solid_waste_practices'] ?? null);
        $this->assertSame('good_practice', $record['solid_waste_status'] ?? null);
    }

    public function test_browser_supplied_solid_waste_status_cannot_override_server_derivation(): void
    {
        $householdNo = $this->seedThroughStep3('HH-809');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
            'solid_waste_status' => 'good_practice',
        ])->assertRedirect(route('spot-mapping.index'));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame('good_practice', $record['solid_waste_status'] ?? null);
        $this->assertSame([
            DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
        ], $record['solid_waste_practices'] ?? null);

        $householdNoTwo = $this->seedThroughStep3('HH-809B');

        $this->from(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNoTwo,
        ]))->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNoTwo,
        ]), [
            'household_no' => $householdNoTwo,
            'solid_waste_status' => 'good_practice',
        ])->assertSessionHasErrors(['solid_waste_practices']);

        $record = DemoHouseholdWaterSupply::find($householdNoTwo);
        $this->assertNotSame('good_practice', $record['solid_waste_status'] ?? null);
        $this->assertSame(3, (int) ($record['step'] ?? 0));
    }

    public function test_cross_actor_access_is_rejected_for_step4(): void
    {
        $householdNo = $this->seedThroughStep3('HH-810');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->get(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]))->assertOk();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 3,
        ]);

        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]))->assertRedirect(route('spot-mapping.index'));

        $this->from(route('spot-mapping.index'))->post(
            route('environmental-health.household-water-supply.step4.store', [
                'householdNo' => $householdNo,
            ]),
            [
                'household_no' => $householdNo,
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                ],
            ]
        )->assertSessionHasErrors([
            'household_no' => SpotMappingHandoffService::INVALID_MESSAGE,
        ]);

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 3,
        ]);
        $this->assertDatabaseMissing('household_solid_waste_practices', [
            'waste_segregation' => 1,
        ]);
    }

    public function test_step4_persists_boolean_flags_and_derived_status(): void
    {
        $householdNo = $this->seedThroughStep3('HH-820');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
            ],
            'solid_waste_status' => 'not_yet_determined',
            'completed_step' => 999,
            'household_id' => 99999,
        ])->assertRedirect(route('spot-mapping.index'));

        $profile = \App\Models\HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->id)
            ->firstOrFail();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'solid_waste_status' => 'good_practice',
            'completed_step' => 4,
        ]);
        $this->assertDatabaseHas('household_solid_waste_practices', [
            'household_environmental_profile_id' => $profile->id,
            'waste_segregation' => 1,
            'backyard_composting' => 0,
            'recycling_reuse' => 0,
            'municipal_collection' => 1,
        ]);
        $this->assertSame(1, \App\Models\HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count());
        $this->assertSame(1, Household::query()->count());
    }

    public function test_repeat_step4_updates_same_solid_waste_row(): void
    {
        $householdNo = $this->seedThroughStep3('HH-821');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ])->assertRedirect();

        $profile = \App\Models\HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->id)
            ->firstOrFail();

        $this->assertSame(1, \App\Models\HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profile->id)
            ->count());
        $this->assertDatabaseHas('household_solid_waste_practices', [
            'household_environmental_profile_id' => $profile->id,
            'waste_segregation' => 0,
            'backyard_composting' => 1,
            'recycling_reuse' => 1,
            'municipal_collection' => 0,
        ]);
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
        ]);
    }

    public function test_step4_get_prefills_db_practices_after_session_field_flush(): void
    {
        $householdNo = $this->seedThroughStep3('HH-822');

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
            ],
        ])->assertRedirect();

        // Re-link after redirect to spot mapping (wizard completion leaves Spot Mapping).
        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'confirm_replot' => true,
            'client_marker_id' => 'client-marker-step4-revisit',
        ])->assertOk();
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        session([DemoHouseholdWaterSupply::SESSION_KEY => []]);

        $html = $this->get(route('environmental-health.household-water-supply.step4', [
            'householdNo' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="solid_waste_practices\[\]"\s+value="recycling_reuse"[^>]*checked|value="recycling_reuse"[^>]*name="solid_waste_practices\[\]"[^>]*checked/',
            $html
        );
    }

    public function test_soft_deleted_household_cannot_post_step4(): void
    {
        $householdNo = $this->seedThroughStep3('HH-823');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();
        $this->archiveHouseholdForWizard($household);

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertSessionHasErrors('household_no');

        $this->assertSame(0, \App\Models\HouseholdSolidWastePractice::query()->count());
    }

    public function test_repeat_step1_after_step4_does_not_decrease_completed_step(): void
    {
        $householdNo = $this->seedThroughStep3('HH-824');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step4.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
        ]);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'Non-HHTS',
            'zone' => '1',
            'lat' => 13.3900,
            'lng' => 123.4400,
            'consent' => true,
            'confirm_replot' => true,
            'client_marker_id' => 'client-marker-step1-revisit',
        ])->assertOk();
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))
            ->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_ii',
            'water_source_location' => 'no',
            'water_availability' => 'yes',
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
            'water_supply_status' => 'level_ii',
            'household_type' => DemoHouseholdWaterSupply::HOUSEHOLD_TYPE_NHTS,
        ]);
        $this->assertSame(1, \App\Models\HouseholdEnvironmentalProfile::query()
            ->where('household_id', $household->id)
            ->count());
    }

    private function archiveHouseholdForWizard(Household $household): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('households', 'deleted_at')) {
            \Illuminate\Support\Facades\DB::table('households')
                ->where($household->getKeyName(), $household->getKey())
                ->update(['deleted_at' => now()]);

            return;
        }

        $household->delete();
    }
}
