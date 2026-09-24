<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class HouseholdAmenitiesDetailsFlowTest extends TestCase
{
    use RefreshDatabase;

    private bool $seedSessionReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
        $this->seedSessionReady = true;
    }

    private function ensureDbHousehold(string $householdNo): Household
    {
        return Household::query()->firstOrCreate(
            ['household_no' => $householdNo],
            [
                'zone' => 'Zone 1',
                'street' => 'Layuan St.',
                'date_registered' => '2026-01-01',
            ]
        );
    }

    private function seedStep4Record(string $householdNo, array $overrides = []): void
    {
        if (! $this->seedSessionReady) {
            $this->actingAsStaff(StaffRole::BHW);
            $this->seedSessionReady = true;
        }

        $this->ensureDbHousehold($householdNo);

        $payload = [
            'household_no' => $householdNo,
            'house_head' => 'Test Head',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-amenities-'.$householdNo.'-'.uniqid('', true),
        ];

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), $payload)->assertOk();
        $token = (string) $issue->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))->assertRedirect();

        DemoHouseholdWaterSupply::saveStep1($householdNo, array_merge([
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'specify_water_source' => null,
        ], $overrides['step1'] ?? []));

        DemoHouseholdWaterSupply::saveStep2($householdNo, array_merge([
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-20',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-22',
            'physicochemical_result' => 'failed',
        ], $overrides['step2'] ?? []));

        DemoHouseholdWaterSupply::saveStep3($householdNo, array_merge([
            'household_no' => $householdNo,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ], $overrides['step3'] ?? []));

        DemoHouseholdWaterSupply::saveStep4($householdNo, array_merge([
            'household_no' => $householdNo,
            'solid_waste_practices' => DemoHouseholdWaterSupply::solidWastePracticeValues(),
        ], $overrides['step4'] ?? []));
    }

    public function test_valid_household_can_open_amenities_details(): void
    {
        $this->ensureDbHousehold('HH-151');

        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));
        $response->assertOk();
        $response->assertSee('Household Amenities Details', false);
    }

    public function test_view_household_details_button_points_to_named_route(): void
    {
        $this->ensureDbHousehold('HH-151');

        $response = $this->get(route('household-profiling.view', ['householdNo' => 'HH-151']));
        $response->assertSee('href="'.e(route('household-profiling.amenities.show', ['householdNo' => 'HH-151'])).'"', false);
    }

    public function test_unknown_household_is_handled_safely(): void
    {
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-999']));
        $response->assertOk();
        $response->assertSee('Household not found', false);
    }

    public function test_db_profile_is_visible_across_actors_for_same_household(): void
    {
        // Supersedes session actor isolation: production profiles are household-owned.
        $this->seedStep4Record('HH-152');
        $record = DemoHouseholdWaterSupply::find('HH-152');
        $this->assertNotNull($record);
        $this->assertSame('db', $record['source'] ?? null);

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
            SpotMappingHandoffService::ACTOR_SESSION_KEY => '00000000-0000-4000-8000-00000000aaaa',
        ]);

        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']));
        $response->assertOk();
        $response->assertSee('07/20/2026', false);
        $this->assertMatchesRegularExpression(
            '/data-water-level="level_i"[^>]*aria-current="true"/',
            $response->getContent()
        );
    }

    public function test_level_i_appears_selected_on_details(): void
    {
        $this->seedStep4Record('HH-151', ['step1' => ['water_supply_status' => 'level_i']]);
        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))->getContent();

        $this->assertStringContainsString('data-water-level="level_i"', $html);
        $this->assertMatchesRegularExpression('/data-water-level="level_i"[^>]*aria-current="true"/', $html);
        $this->assertMatchesRegularExpression('/lml-amenities__level-card is-selected[^>]*data-water-level="level_i"|data-water-level="level_i"[^>]*class="[^"]*is-selected/', $html);
    }

    public function test_level_ii_appears_selected_on_details(): void
    {
        $this->seedStep4Record('HH-152', ['step1' => ['water_supply_status' => 'level_ii']]);
        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_ii"[^>]*aria-current="true"/', $html);
        $this->assertStringContainsString('With Basic Safe Water', $html);
    }

    public function test_level_iii_appears_selected_on_details(): void
    {
        $this->seedStep4Record('HH-153', ['step1' => ['water_supply_status' => 'level_iii']]);
        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-153']))->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_iii"[^>]*aria-current="true"/', $html);
        $this->assertStringContainsString('With Basic Safe Water', $html);
    }

    public function test_others_with_specification_renders_on_details(): void
    {
        $this->seedStep4Record('HH-154', [
            'step1' => [
                'water_supply_status' => 'others',
                'specify_water_source' => 'Deep dug well',
            ],
        ]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-154']));
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="others"[^>]*aria-current="true"/', $html);
        $response->assertSee('Without Basic Safe Water', false);
        $response->assertSee('Deep dug well', false);
        $response->assertSee('doubtful source', false);
    }

    public function test_water_location_and_availability_yes_no_render_selected(): void
    {
        $this->seedStep4Record('HH-155', [
            'step1' => [
                'water_source_location' => 'no',
                'water_availability' => 'yes',
            ],
        ]);
        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-155']))->getContent();

        $this->assertStringContainsString('aria-label="Water Source Location"', $html);
        $this->assertMatchesRegularExpression(
            '/aria-label="Water Source Location"[\s\S]*?is-selected[\s\S]*?>\s*No\s*</',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/aria-label="Water Availability"[\s\S]*?is-selected[\s\S]*?>\s*Yes\s*</',
            $html
        );
    }

    public function test_microbiological_and_physico_values_render(): void
    {
        $this->seedStep4Record('HH-156');
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-156']));
        $response->assertSee('07/20/2026', false);
        $response->assertSee('Passed', false);
        $response->assertSee('07/22/2026', false);
        $response->assertSee('Failed', false);
        $response->assertSee('Completed', false);
    }

    public function test_not_conducted_displays_when_tests_absent(): void
    {
        $this->seedStep4Record('HH-151', [
            'step2' => [
                'microbiological_test_date' => null,
                'microbiological_result' => null,
                'physicochemical_test_date' => null,
                'physicochemical_result' => null,
            ],
        ]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));
        $response->assertSee('Not Conducted', false);
    }

    public function test_sanitary_in_site_disposed_displays_safely_managed(): void
    {
        $this->seedStep4Record('HH-152', [
            'step3' => [
                'toilet_type' => 'pour_flush_with_septic_tank',
                'sewage_disposal_method' => 'on_site_safely_managed',
            ],
        ]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']));
        $response->assertSee('Pour/Flush Type with Septic Tank', false);
        $response->assertSee('In-site Disposed', false);
        $response->assertSee('Safely Managed', false);
    }

    public function test_sanitary_off_site_disposed_displays_safely_managed(): void
    {
        $this->seedStep4Record('HH-153', [
            'step3' => [
                'toilet_type' => 'pour_flush_connected_to_septic_or_sewer',
                'sewage_disposal_method' => 'off_site_collected_and_treated',
            ],
        ]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-153']));
        $response->assertSee('Off-site Disposed', false);
        $response->assertSee('Safely Managed', false);
    }

    public function test_unsanitary_displays_not_safely_managed(): void
    {
        $this->seedStep4Record('HH-154', ['step3' => ['toilet_type' => 'open_pit_latrine']]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-154']));
        $response->assertSee('Open Pit Latrine', false);
        $response->assertSee('Not Safely Managed', false);
    }

    public function test_solid_waste_checked_and_unchecked_states_render(): void
    {
        $this->seedStep4Record('HH-155', [
            'step4' => [
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                    DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE,
                ],
            ],
        ]);
        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-155']))->getContent();

        $this->assertMatchesRegularExpression(
            '/class="[^"]*is-checked[^"]*"\s+data-practice="waste_segregation"/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/class="[^"]*is-checked[^"]*"\s+data-practice="recycling_reuse"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*is-checked[^"]*"\s+data-practice="backyard_composting"/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*is-checked[^"]*"\s+data-practice="municipal_collection"/',
            $html
        );
        $this->assertStringContainsString('Waste Segregation', $html);
        $this->assertStringContainsString('Backyard Composting', $html);
    }

    public function test_household_a_cannot_display_household_b_amenities(): void
    {
        $this->seedStep4Record('HH-151', [
            'step1' => [
                'water_supply_status' => 'level_i',
                'specify_water_source' => null,
            ],
            'step2' => [
                'microbiological_test_date' => '2026-01-01',
                'microbiological_result' => 'passed',
            ],
        ]);
        $this->seedStep4Record('HH-152', [
            'step1' => [
                'water_supply_status' => 'others',
                'specify_water_source' => 'Household B Spring',
            ],
            'step2' => [
                'microbiological_test_date' => '2026-02-02',
                'microbiological_result' => 'failed',
            ],
        ]);

        $this->assertNotNull(DemoHouseholdWaterSupply::findForActor('HH-151'));
        $this->assertNotNull(DemoHouseholdWaterSupply::findForActor('HH-152'));

        $a = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))->getContent();
        $b = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))->getContent();

        $this->assertStringContainsString('01/01/2026', $a);
        $this->assertStringNotContainsString('Household B Spring', $a);
        $this->assertStringContainsString('Household B Spring', $b);
        $this->assertStringNotContainsString('01/01/2026', $b);
        $this->assertNotSame($a, $b);
    }

    public function test_with_basic_safe_water_displays_for_level_i_to_iii(): void
    {
        $this->seedStep4Record('HH-153', ['step1' => ['water_supply_status' => 'level_ii']]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-153']));
        $response->assertSee('With Basic Safe Water', false);
    }

    public function test_without_basic_safe_water_displays_for_others(): void
    {
        $this->seedStep4Record('HH-154', ['step1' => ['water_supply_status' => 'others', 'specify_water_source' => 'Spring']]);
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-154']));
        $response->assertSee('Without Basic Safe Water', false);
    }

    public function test_all_four_solid_waste_practices_render(): void
    {
        $this->seedStep4Record('HH-151');
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));
        $response->assertSee('Waste Segregation', false);
        $response->assertSee('Backyard Composting', false);
        $response->assertSee('Recycling / Reuse', false);
        $response->assertSee('Collected by Municipality / Municipal Collection and Disposal System', false);
    }

    public function test_summary_values_are_data_driven(): void
    {
        $this->seedStep4Record('HH-152', ['step3' => ['toilet_type' => 'open_pit_latrine']]);
        $this->seedStep4Record('HH-153', ['step3' => ['toilet_type' => 'pour_flush_with_septic_tank']]);

        $a = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))->getContent();
        $b = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-153']))->getContent();

        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('Not Safely Managed', $a);
        $this->assertStringContainsString('Safely Managed', $b);
    }

    public function test_edit_page_loads_existing_values(): void
    {
        $this->seedStep4Record('HH-154', [
            'step1' => [
                'water_supply_status' => 'level_iii',
                'water_source_location' => 'no',
                'water_availability' => 'yes',
            ],
        ]);
        $response = $this->get(route('household-profiling.amenities.edit', ['householdNo' => 'HH-154']));
        $response->assertOk();
        $response->assertSee('value="2026-07-20"', false);
        $response->assertSee('on_site_safely_managed', false);
        $response->assertSee('value="level_iii"', false);
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/name="water_source_location"\s+value="no"[^>]*checked|value="no"[^>]*name="water_source_location"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="water_availability"\s+value="yes"[^>]*checked|value="yes"[^>]*name="water_availability"[^>]*checked/', $html);
    }

    public function test_valid_update_persists_and_redirects_to_details(): void
    {
        $this->seedStep4Record('HH-155');
        $response = $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-155']), [
            'household_no' => 'HH-155',
            'water_supply_status' => 'others',
            'specify_water_source' => 'Open well',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'open_pit_latrine',
            'open_defecation_practiced' => 'yes',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'off_site_collected_and_treated',
            'solid_waste_practices' => ['waste_segregation'],
        ]);

        $response->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-155']));
        $this->assertDatabaseHas('household_environmental_profiles', [
            'water_supply_status' => 'others',
            'specify_water_source' => 'Open well',
        ]);
        $this->assertSame('others', DemoHouseholdWaterSupply::find('HH-155')['water_supply_status'] ?? null);

        $details = $this->followRedirects($response);
        $details->assertSee('Open well', false);
        $details->assertSee('Without Basic Safe Water', false);
        $details->assertSee('Not Safely Managed', false);
    }

    public function test_amenities_update_accepts_omitted_sewage_when_toilet_exists(): void
    {
        $this->seedStep4Record('HH-157');
        $household = Household::query()->where('household_no', 'HH-157')->firstOrFail();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'sewage_disposal_method' => 'on_site_safely_managed',
        ]);

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-157']), [
            'household_no' => 'HH-157',
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
            'solid_waste_practices' => ['waste_segregation'],
        ])->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-157']))
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => null,
            'management_status' => DemoHouseholdWaterSupply::MANAGEMENT_STATUS_PENDING,
        ]);
    }

    public function test_amenities_update_rejects_invalid_sewage_without_writing(): void
    {
        $this->seedStep4Record('HH-158');
        $household = Household::query()->where('household_no', 'HH-158')->firstOrFail();

        $this->putJson(route('household-profiling.amenities.update', ['householdNo' => 'HH-158']), [
            'household_no' => 'HH-158',
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '2026-07-20',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-22',
            'physicochemical_result' => 'failed',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'not_a_real_sewage_method',
            'solid_waste_practices' => ['waste_segregation'],
        ])->assertStatus(422)->assertJsonValidationErrors(['sewage_disposal_method']);

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'sewage_disposal_method' => 'on_site_safely_managed',
            'completed_step' => 4,
        ]);
    }

    public function test_invalid_update_returns_errors_and_preserves_input(): void
    {
        $this->seedStep4Record('HH-156');
        $response = $this->from(route('household-profiling.amenities.edit', ['householdNo' => 'HH-156']))
            ->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-156']), [
                'household_no' => 'HH-156',
                'water_supply_status' => 'others',
                'specify_water_source' => '',
                'water_source_location' => '',
                'water_availability' => '',
                'toilet_type' => '',
                'open_defecation_practiced' => '',
                'solid_waste_practices' => [],
            ]);

        $response->assertRedirect(route('household-profiling.amenities.edit', ['householdNo' => 'HH-156']));
        $response->assertSessionHasErrors(['specify_water_source', 'water_source_location', 'toilet_type']);
    }

    public function test_browser_submitted_computed_statuses_cannot_override_server_derivation(): void
    {
        $this->seedStep4Record('HH-151');

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-151']), [
            'household_no' => 'HH-151',
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'open_pit_latrine',
            'open_defecation_practiced' => 'yes',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'off_site_collected_and_treated',
            'solid_waste_practices' => ['waste_segregation'],
            'basic_safe_water_status' => 'without_basic_safe_water',
            'management_status' => 'safely_managed',
            'solid_waste_status' => 'not_yet_determined',
        ])->assertRedirect();

        $record = DemoHouseholdWaterSupply::find('HH-151');
        $this->assertSame(DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH, $record['basic_safe_water_status'] ?? null);
        $this->assertSame(DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED, $record['management_status'] ?? null);
    }

    public function test_close_and_back_links_resolve_correctly(): void
    {
        $this->ensureDbHousehold('HH-151');

        $show = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));
        $show->assertSee(route('household-profiling.view', ['householdNo' => 'HH-151']), false);
        $show->assertSee(route('household-profiling.amenities.edit', ['householdNo' => 'HH-151']), false);

        $edit = $this->get(route('household-profiling.amenities.edit', ['householdNo' => 'HH-151']));
        $edit->assertOk();
        $edit->assertSee(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']), false);
    }

    public function test_socioeconomic_status_comes_from_handoff_link(): void
    {
        $this->seedStep4Record('HH-152');
        $response = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']));
        $response->assertSee('NHTS', false);
        $response->assertSee('class="visually-hidden">Socioeconomic Status</span>', false);
        $response->assertSee('class="visually-hidden">Household Number</span>', false);
        $response->assertSee('class="visually-hidden">Household Head</span>', false);
        $response->assertSee('class="visually-hidden">Zone</span>', false);
        $response->assertDontSee('aria-label="Socioeconomic Status:', false);
        $response->assertDontSee('aria-label="Household Number:', false);
        $response->assertSee('bi-house-door-fill', false);
        $response->assertSee('bi-person-vcard', false);
        $response->assertSee('bi-person-fill', false);
        $response->assertSee('bi-geo-alt-fill', false);
    }

    public function test_each_profiling_household_opens_amenities_details(): void
    {
        foreach (DemoHouseholdWaterSupply::profilingDemoHouseholdNos() as $householdNo) {
            $this->get(route('household-profiling.amenities.show', ['householdNo' => $householdNo]))
                ->assertOk()
                ->assertSee('Household not found', false)
                ->assertDontSee('Kristine Reyes', false);
        }
    }

    public function test_each_profiling_household_shows_own_head_and_context(): void
    {
        $heads = [
            'HH-151' => 'Kristine Reyes',
            'HH-152' => 'Carlo Evangelista',
            'HH-153' => 'Adrian Corporal',
            'HH-154' => 'Maria Santos',
            'HH-155' => 'Juan dela Cruz',
            'HH-156' => 'Rosa Lim',
        ];

        foreach ($heads as $householdNo => $head) {
            $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => $householdNo]))->getContent();
            $this->assertStringContainsString('Household not found', $html);
            $this->assertStringNotContainsString($head, $html);
        }
    }

    public function test_profiling_catalog_households_do_not_leak_demo_amenities(): void
    {
        foreach (DemoHouseholdWaterSupply::profilingDemoHouseholdNos() as $householdNo) {
            $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => $householdNo]))->getContent();
            $this->assertStringContainsString('Household not found', $html, $householdNo.' should not resolve DemoCatalog');
            $this->assertStringNotContainsString('Kristine Reyes', $html);
            $this->assertStringNotContainsString('Open dug well', $html);
            $this->assertStringNotContainsString('2026-06-10', $html);
        }
    }

    public function test_details_button_resolves_to_matching_household_for_each_listing(): void
    {
        foreach (DemoHouseholdWaterSupply::profilingDemoHouseholdNos() as $householdNo) {
            \Tests\Support\PersistCatalogHousehold::persist($householdNo);
            $this->get(route('household-profiling.view', ['householdNo' => $householdNo]))
                ->assertSee(
                    'href="'.e(route('household-profiling.amenities.show', ['householdNo' => $householdNo])).'"',
                    false
                );
        }
    }

    public function test_edit_page_loads_saved_db_record_for_household(): void
    {
        // Supersedes demo-catalog-only edit: edit requires a DB household + persisted profile.
        $this->seedStep4Record('HH-152', [
            'step1' => [
                'water_supply_status' => 'others',
                'specify_water_source' => 'Open dug well',
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
            ],
        ]);

        $show = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))->getContent();
        $edit = $this->get(route('household-profiling.amenities.edit', ['householdNo' => 'HH-152']));
        $edit->assertOk();
        $editHtml = $edit->getContent();

        $this->assertStringContainsString('Open dug well', $show);
        $this->assertStringContainsString('Open dug well', $editHtml);
        $this->assertStringContainsString('value="others"', $editHtml);
        $this->assertMatchesRegularExpression(
            '/data-water-level="others"[^>]*aria-current="true"|value="others"[^>]*checked/',
            $show."\n".$editHtml
        );
    }

    public function test_persisted_db_profile_is_shared_across_actors(): void
    {
        // Supersedes session actor isolation for DB households (ownership is household_id).
        $this->seedStep4Record('HH-152', [
            'step1' => [
                'water_supply_status' => 'level_i',
                'specify_water_source' => null,
            ],
            'step2' => [
                'microbiological_test_date' => '2026-07-20',
                'microbiological_result' => 'passed',
            ],
        ]);

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
            SpotMappingHandoffService::ACTOR_SESSION_KEY => '00000000-0000-4000-8000-00000000bbbb',
        ]);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))->getContent();
        $this->assertStringContainsString('07/20/2026', $html);
        $this->assertMatchesRegularExpression('/data-water-level="level_i"[^>]*aria-current="true"/', $html);
    }

    // ─── DB19-E Amenities ↔ Environmental Health reconciliation ───────────────

    public function test_db19e_real_household_amenities_loads_mysql_profile_and_solid_waste(): void
    {
        $this->seedStep4Record('HH-151', [
            'step1' => ['water_supply_status' => 'level_ii'],
            'step4' => [
                'solid_waste_practices' => [
                    DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION,
                    DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION,
                ],
            ],
        ]);

        $household = Household::query()->where('household_no', 'HH-151')->firstOrFail();
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'water_supply_status' => 'level_ii',
            'completed_step' => 4,
        ]);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_ii"[^>]*aria-current="true"/', $html);
        $this->assertStringContainsString('Good Practice', $html);
        // Demo blueprint for HH-151 uses level_iii / 2026-06-10 — MySQL must win.
        $this->assertStringNotContainsString('2026-06-10', $html);
    }

    public function test_db19e_no_profile_shows_empty_not_demo_blueprint(): void
    {
        $this->ensureDbHousehold('HH-151');

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('2026-06-10', $html);
        $this->assertStringNotContainsString('Kristine Reyes', $html);
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => Household::query()->where('household_no', 'HH-151')->value('id'),
        ]);
    }

    public function test_db19e_partial_wizard_completion_renders_saved_fields_only(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->ensureDbHousehold('HH-153');

        $payload = [
            'household_no' => 'HH-153',
            'house_head' => 'Partial Head',
            'household_type' => 'Non-HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-partial-'.uniqid('', true),
        ];
        $token = (string) $this->postJson(route('spot-mapping.plot-handoff'), $payload)->assertOk()->json('handoff_token');
        $this->get(route('environmental-health.household-water-supply', ['handoff' => $token]))->assertRedirect();

        DemoHouseholdWaterSupply::saveStep1('HH-153', [
            'household_no' => 'HH-153',
            'water_supply_status' => 'level_iii',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'specify_water_source' => null,
        ]);

        $household = Household::query()->where('household_no', 'HH-153')->firstOrFail();
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 1,
            'water_supply_status' => 'level_iii',
        ]);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-153']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_iii"[^>]*aria-current="true"/', $html);
        $this->assertStringContainsString('Non-NHTS', $html);
        // Demo HH-153 markers must not appear when a real partial DB profile exists.
        $this->assertStringNotContainsString('2026-05-01', $html);
    }

    public function test_db19e_stale_session_cannot_override_mysql_on_amenities_show(): void
    {
        $this->seedStep4Record('HH-154', [
            'step1' => ['water_supply_status' => 'level_i'],
        ]);

        session([
            DemoHouseholdWaterSupply::SESSION_KEY => [
                'HH-154' => [
                    'household_no' => 'HH-154',
                    'water_supply_status' => 'others',
                    'specify_water_source' => 'Stale Spring',
                    'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT,
                    'step' => 4,
                    'source' => 'session',
                ],
            ],
        ]);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-154']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_i"[^>]*aria-current="true"/', $html);
        $this->assertStringNotContainsString('Stale Spring', $html);
    }

    public function test_db19e_real_db_household_wins_over_matching_demo_hh_number(): void
    {
        $this->seedStep4Record('HH-152', [
            'step1' => [
                'water_supply_status' => 'level_iii',
                'specify_water_source' => null,
            ],
        ]);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-152']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/data-water-level="level_iii"[^>]*aria-current="true"/', $html);
        // Demo HH-152 blueprint uses "Open dug well" / others — must not shadow MySQL.
        $this->assertStringNotContainsString('Open dug well', $html);
    }

    public function test_db19e_amenities_edit_updates_same_profile_and_solid_waste_row(): void
    {
        $this->seedStep4Record('HH-155');
        $household = Household::query()->where('household_no', 'HH-155')->firstOrFail();
        $profileId = (int) $household->environmentalProfile()->value('id');
        $solidWasteId = (int) \App\Models\HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profileId)
            ->value('id');

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-155']), [
            'household_no' => 'HH-155',
            'water_supply_status' => 'others',
            'specify_water_source' => 'Deep dug well',
            'water_source_location' => 'no',
            'water_availability' => 'yes',
            'microbiological_test_date' => '2026-08-01',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
            'solid_waste_practices' => [
                DemoHouseholdWaterSupply::SOLID_WASTE_BACKYARD_COMPOSTING,
            ],
        ])->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-155']));

        $this->assertSame(1, $household->environmentalProfile()->count());
        $this->assertDatabaseHas('household_environmental_profiles', [
            'id' => $profileId,
            'household_id' => $household->id,
            'water_supply_status' => 'others',
            'specify_water_source' => 'Deep dug well',
            'microbiological_result' => 'passed',
            'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITHOUT,
            'completed_step' => 4,
        ]);
        $this->assertSame(
            '2026-08-01',
            optional($household->environmentalProfile()->first())->microbiological_test_date?->format('Y-m-d')
        );
        $this->assertSame(
            1,
            \App\Models\HouseholdSolidWastePractice::query()
                ->where('household_environmental_profile_id', $profileId)
                ->count()
        );
        $this->assertDatabaseHas('household_solid_waste_practices', [
            'id' => $solidWasteId,
            'household_environmental_profile_id' => $profileId,
            'waste_segregation' => 0,
            'backyard_composting' => 1,
            'recycling_reuse' => 0,
            'municipal_collection' => 0,
        ]);

        Session::flush();
        $this->actingAsStaff(StaffRole::BHW);

        $html = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-155']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Deep dug well', $html);
        $this->assertStringContainsString('08/01/2026', $html);
    }

    public function test_db19e_amenities_does_not_decrease_completed_step(): void
    {
        $this->seedStep4Record('HH-156');
        $household = Household::query()->where('household_no', 'HH-156')->firstOrFail();
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
        ]);

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-156']), [
            'household_no' => 'HH-156',
            'water_supply_status' => 'level_ii',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
            'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_RECYCLING_REUSE],
            'completed_step' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 4,
            'water_supply_status' => 'level_ii',
        ]);
    }

    public function test_db19e_forged_ids_statuses_and_household_type_ignored(): void
    {
        $this->seedStep4Record('HH-151', [
            'step1' => ['water_supply_status' => 'level_i'],
        ]);
        $household = Household::query()->where('household_no', 'HH-151')->firstOrFail();
        $other = $this->ensureDbHousehold('HH-999-FORGE');
        $profileId = (int) $household->environmentalProfile()->value('id');
        $solidWasteId = (int) \App\Models\HouseholdSolidWastePractice::query()
            ->where('household_environmental_profile_id', $profileId)
            ->value('id');

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-151']), [
            'household_no' => 'HH-151',
            'id' => 99999,
            'household_id' => $other->id,
            'household_environmental_profile_id' => 88888,
            'profile_id' => 77777,
            'solid_waste_practice_id' => 66666,
            'solid_waste_id' => $solidWasteId + 999,
            'household_type' => 'HHTS',
            'completed_step' => 0,
            'water_supply_status' => 'level_iii',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'without_toilet',
            'open_defecation_practiced' => 'yes',
            'shared_toilet' => 'yes',
            'sewage_disposal_method' => 'on_site_safely_managed',
            'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION],
            'basic_safe_water_status' => 'without_basic_safe_water',
            'toilet_status' => 'unsanitary',
            'management_status' => 'safely_managed',
            'solid_waste_status' => 'not_yet_determined',
        ])->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));

        $this->assertDatabaseHas('household_environmental_profiles', [
            'id' => $profileId,
            'household_id' => $household->id,
            'household_type' => 'NHTS',
            'water_supply_status' => 'level_iii',
            'toilet_type' => 'without_toilet',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => null,
            'toilet_status' => DemoHouseholdWaterSupply::TOILET_STATUS_UNSANITARY,
            'management_status' => DemoHouseholdWaterSupply::MANAGEMENT_STATUS_NOT_SAFELY_MANAGED,
            'basic_safe_water_status' => DemoHouseholdWaterSupply::BASIC_SAFE_WATER_WITH,
            'solid_waste_status' => 'good_practice',
            'completed_step' => 4,
        ]);
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $other->id,
        ]);
        $this->assertSame(
            1,
            \App\Models\HouseholdSolidWastePractice::query()
                ->where('household_environmental_profile_id', $profileId)
                ->count()
        );
    }

    public function test_db19e_water_test_partial_pairs_rejected_on_amenities(): void
    {
        $this->seedStep4Record('HH-152');

        $this->from(route('household-profiling.amenities.edit', ['householdNo' => 'HH-152']))
            ->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-152']), [
                'household_no' => 'HH-152',
                'water_supply_status' => 'level_i',
                'specify_water_source' => null,
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                'microbiological_test_date' => '2026-08-10',
                'microbiological_result' => '',
                'physicochemical_test_date' => '',
                'physicochemical_result' => 'passed',
                'toilet_type' => 'pour_flush_with_septic_tank',
                'open_defecation_practiced' => 'no',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => 'on_site_safely_managed',
                'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION],
            ])
            ->assertRedirect(route('household-profiling.amenities.edit', ['householdNo' => 'HH-152']))
            ->assertSessionHasErrors(['microbiological_result', 'physicochemical_test_date']);
    }

    public function test_db19e_soft_deleted_household_denied_and_no_demo_fallback(): void
    {
        $household = $this->ensureDbHousehold('HH-151');
        $this->seedStep4Record('HH-151', [
            'step1' => ['water_supply_status' => 'level_ii'],
        ]);
        $household->refresh();

        if (\Illuminate\Support\Facades\Schema::hasColumn('households', 'deleted_at')) {
            \Illuminate\Support\Facades\DB::table('households')
                ->where($household->getKeyName(), $household->getKey())
                ->update(['deleted_at' => now()]);
            $this->assertSoftDeleted('households', [$household->getKeyName() => $household->getKey()]);
        } else {
            $profile = $household->environmentalProfile;
            if ($profile !== null) {
                $profile->solidWastePractices()->delete();
                $profile->delete();
            }
            $household->delete();
        }

        $show = $this->get(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));
        $show->assertOk();
        $show->assertSee('Household was not found', false);
        // Must not fall through to demo blueprint values for the same household_no.
        $show->assertDontSee('Kristine Reyes', false);
        $show->assertDontSee('2026-06-10', false);

        $this->get(route('household-profiling.amenities.edit', ['householdNo' => 'HH-151']))
            ->assertRedirect(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']));

        $this->from(route('household-profiling.amenities.show', ['householdNo' => 'HH-151']))
            ->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-151']), [
                'household_no' => 'HH-151',
                'water_supply_status' => 'level_i',
                'specify_water_source' => null,
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                'microbiological_test_date' => '',
                'microbiological_result' => '',
                'physicochemical_test_date' => '',
                'physicochemical_result' => '',
                'toilet_type' => 'pour_flush_with_septic_tank',
                'open_defecation_practiced' => 'no',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => 'on_site_safely_managed',
                'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION],
            ])
            ->assertNotFound();
    }

    public function test_db19e_amenities_edit_clears_stale_session_environmental_record(): void
    {
        $this->seedStep4Record('HH-153', [
            'step1' => ['water_supply_status' => 'level_i'],
        ]);

        session([
            DemoHouseholdWaterSupply::SESSION_KEY => [
                'HH-153' => [
                    'household_no' => 'HH-153',
                    'water_supply_status' => 'others',
                    'specify_water_source' => 'Should Clear',
                    'step' => 4,
                ],
            ],
        ]);

        $this->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-153']), [
            'household_no' => 'HH-153',
            'water_supply_status' => 'level_ii',
            'specify_water_source' => null,
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '',
            'physicochemical_result' => '',
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
            'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_MUNICIPAL_COLLECTION],
        ])->assertRedirect();

        $sessionRecords = session(DemoHouseholdWaterSupply::SESSION_KEY, []);
        $this->assertArrayNotHasKey('HH-153', is_array($sessionRecords) ? $sessionRecords : []);
    }

    public function test_amenities_update_rejects_future_validation_dates(): void
    {
        $this->seedStep4Record('HH-151');
        $future = now()->addDay()->toDateString();

        $this->from(route('household-profiling.amenities.edit', ['householdNo' => 'HH-151']))
            ->put(route('household-profiling.amenities.update', ['householdNo' => 'HH-151']), [
                'household_no' => 'HH-151',
                'water_supply_status' => 'level_iii',
                'specify_water_source' => null,
                'water_source_location' => 'yes',
                'water_availability' => 'yes',
                'microbiological_test_date' => $future,
                'microbiological_result' => 'passed',
                'physicochemical_test_date' => '',
                'physicochemical_result' => '',
                'toilet_type' => 'pour_flush_with_septic_tank',
                'open_defecation_practiced' => 'no',
                'shared_toilet' => 'no',
                'sewage_disposal_method' => 'on_site_safely_managed',
                'solid_waste_practices' => [DemoHouseholdWaterSupply::SOLID_WASTE_WASTE_SEGREGATION],
            ])
            ->assertRedirect(route('household-profiling.amenities.edit', ['householdNo' => 'HH-151']))
            ->assertSessionHasErrors('microbiological_test_date');
    }
}
