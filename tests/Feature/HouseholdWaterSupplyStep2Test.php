<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Support\DemoHouseholdWaterSupply;
use App\Services\SpotMappingHandoffService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdWaterSupplyStep2Test extends TestCase
{
    use RefreshDatabase;

    private function seedStep1Household(string $householdNo = 'HH-601'): string
    {
        $this->actingAsStaff(StaffRole::BHW);
        session([
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
            'client_marker_id' => 'client-marker-step2',
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

        return $householdNo;
    }

    public function test_blank_step2_submission_succeeds(): void
    {
        $householdNo = $this->seedStep1Household();

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNotNull($record);
        $this->assertSame(2, (int) ($record['step'] ?? 0));
        $this->assertNull($record['microbiological_test_date'] ?? null);
        $this->assertNull($record['microbiological_result'] ?? null);
        $this->assertNull($record['physicochemical_test_date'] ?? null);
        $this->assertNull($record['physicochemical_result'] ?? null);
        $this->assertSame('not_conducted', DemoHouseholdWaterSupply::validationTestingStatus($record));
    }

    public function test_microbiological_only_succeeds(): void
    {
        $householdNo = $this->seedStep1Household('HH-602');

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-15',
            'microbiological_result' => 'passed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame('2026-07-15', $record['microbiological_test_date'] ?? null);
        $this->assertSame('passed', $record['microbiological_result'] ?? null);
        $this->assertNull($record['physicochemical_test_date'] ?? null);
        $this->assertNull($record['physicochemical_result'] ?? null);
        $this->assertSame('partially_recorded', DemoHouseholdWaterSupply::validationTestingStatus($record));
    }

    public function test_physicochemical_only_succeeds(): void
    {
        $householdNo = $this->seedStep1Household('HH-603');

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'physicochemical_test_date' => '2026-07-16',
            'physicochemical_result' => 'failed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNull($record['microbiological_test_date'] ?? null);
        $this->assertNull($record['microbiological_result'] ?? null);
        $this->assertSame('2026-07-16', $record['physicochemical_test_date'] ?? null);
        $this->assertSame('failed', $record['physicochemical_result'] ?? null);
        $this->assertSame('partially_recorded', DemoHouseholdWaterSupply::validationTestingStatus($record));
    }

    public function test_both_complete_succeeds(): void
    {
        $householdNo = $this->seedStep1Household('HH-604');

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-10',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-11',
            'physicochemical_result' => 'failed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertSame('2026-07-10', $record['microbiological_test_date'] ?? null);
        $this->assertSame('passed', $record['microbiological_result'] ?? null);
        $this->assertSame('2026-07-11', $record['physicochemical_test_date'] ?? null);
        $this->assertSame('failed', $record['physicochemical_result'] ?? null);
        $this->assertSame('completed', DemoHouseholdWaterSupply::validationTestingStatus($record));
    }

    public function test_microbiological_date_without_result_fails(): void
    {
        $householdNo = $this->seedStep1Household('HH-605');

        $response = $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-15',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors([
            'microbiological_result' => 'Please select the microbiological test result.',
        ]);
    }

    public function test_microbiological_result_without_date_fails(): void
    {
        $householdNo = $this->seedStep1Household('HH-606');

        $response = $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_result' => 'passed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors([
            'microbiological_test_date' => 'Please select the microbiological test date.',
        ]);
    }

    public function test_physicochemical_date_without_result_fails(): void
    {
        $householdNo = $this->seedStep1Household('HH-607');

        $response = $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'physicochemical_test_date' => '2026-07-15',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors([
            'physicochemical_result' => 'Please select the physico-chemical test result.',
        ]);
    }

    public function test_physicochemical_result_without_date_fails(): void
    {
        $householdNo = $this->seedStep1Household('HH-608');

        $response = $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'physicochemical_result' => 'failed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionHasErrors([
            'physicochemical_test_date' => 'Please select the physico-chemical test date.',
        ]);
    }

    public function test_existing_saved_values_reload_correctly(): void
    {
        $householdNo = $this->seedStep1Household('HH-609');

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-12',
            'microbiological_result' => 'failed',
            'physicochemical_test_date' => '2026-07-13',
            'physicochemical_result' => 'passed',
        ])->assertRedirect();

        $page = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        $page->assertOk();
        $page->assertSee('Validation / Random Sampling / Testing', false);
        $page->assertSee('Step 1.2 of 4, current', false);
        $page->assertSee('Step 1 of 4, completed', false);
        $page->assertSee('1.2', false);
        $page->assertSee('value="2026-07-12"', false);
        $page->assertSee('value="2026-07-13"', false);
        $page->assertSee('name="microbiological_result"', false);
        $page->assertSee('value="failed"', false);
        $page->assertSee('name="physicochemical_result"', false);
        $page->assertSee('value="passed"', false);
        $page->assertSee('checked', false);
    }

    public function test_clearing_a_section_stores_null_values(): void
    {
        $householdNo = $this->seedStep1Household('HH-610');

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-12',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-13',
            'physicochemical_result' => 'failed',
        ])->assertRedirect();

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '',
            'microbiological_result' => '',
            'physicochemical_test_date' => '2026-07-13',
            'physicochemical_result' => 'failed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNull($record['microbiological_test_date'] ?? null);
        $this->assertNull($record['microbiological_result'] ?? null);
        $this->assertSame('2026-07-13', $record['physicochemical_test_date'] ?? null);
        $this->assertSame('failed', $record['physicochemical_result'] ?? null);
        $this->assertSame('partially_recorded', DemoHouseholdWaterSupply::validationTestingStatus($record));
    }

    public function test_unauthorized_or_unlinked_household_access_remains_blocked(): void
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => 'HH-999',
        ]))->assertRedirect(route('spot-mapping.index'));

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => 'HH-999',
        ]), [
            'household_no' => 'HH-999',
        ])->assertSessionHasErrors(['household_no']);

        $this->get(route('environmental-health.household-water-supply.step3', [
            'householdNo' => 'HH-999',
        ]))->assertRedirect(route('spot-mapping.index'));
    }

    public function test_cross_actor_cannot_view_or_submit_step2_for_another_actors_household(): void
    {
        $householdNo = $this->seedStep1Household('HH-612');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 1,
        ]);

        // Switch actor — link context is actor-scoped; DB profile remains household-owned.
        $this->actingAsStaff(StaffRole::BHW);

        $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertRedirect(route('spot-mapping.index'));

        $this->from(route('spot-mapping.index'))->post(
            route('environmental-health.household-water-supply.step2.store', [
                'householdNo' => $householdNo,
            ]),
            [
                'household_no' => $householdNo,
                'microbiological_test_date' => '2026-07-20',
                'microbiological_result' => 'passed',
            ]
        )->assertSessionHasErrors([
            'household_no' => SpotMappingHandoffService::INVALID_MESSAGE,
        ]);

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 1,
        ]);
        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
            'microbiological_result' => 'passed',
        ]);
    }

    public function test_previous_and_next_workflow_routes_resolve_correctly(): void
    {
        $householdNo = $this->seedStep1Household('HH-611');

        $step2 = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));
        $step2->assertOk();
        $step2->assertSee(route('environmental-health.household-water-supply', [
            'household' => $householdNo,
        ]), false);
        $step2->assertSee(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), false);

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $step3 = $this->get(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));
        $step3->assertOk();
        $step3->assertSee('Basic Sanitation Facility', false);
        $step3->assertSee(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]), false);
        $step3->assertSee('Step 2 of 4, current', false);

        $this->assertTrue(DemoHouseholdWaterSupply::hasCompletedStep2($householdNo));
        $this->assertNotSame(
            SpotMappingHandoffService::INVALID_MESSAGE,
            ''
        );
    }

    public function test_matching_route_and_body_household_number_succeeds(): void
    {
        $householdNo = $this->seedStep1Household('HH-620');

        $response = $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-21',
            'microbiological_result' => 'passed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));
        $response->assertSessionDoesntHaveErrors();

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNotNull($record);
        $this->assertSame(2, (int) ($record['step'] ?? 0));
        $this->assertSame('2026-07-21', $record['microbiological_test_date'] ?? null);
        $this->assertSame('passed', $record['microbiological_result'] ?? null);
    }

    public function test_mismatched_route_and_body_household_number_is_rejected(): void
    {
        $routeHouseholdNo = $this->seedStep1Household('HH-621');
        $bodyHouseholdNo = $this->seedStep1Household('HH-622');

        $beforeRoute = DemoHouseholdWaterSupply::find($routeHouseholdNo);
        $beforeBody = DemoHouseholdWaterSupply::find($bodyHouseholdNo);
        $this->assertNotNull($beforeRoute);
        $this->assertNotNull($beforeBody);
        $this->assertSame(1, (int) ($beforeRoute['step'] ?? 0));
        $this->assertSame(1, (int) ($beforeBody['step'] ?? 0));

        $response = $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $routeHouseholdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $routeHouseholdNo,
        ]), [
            'household_no' => $bodyHouseholdNo,
            'microbiological_test_date' => '2026-07-22',
            'microbiological_result' => 'failed',
        ]);

        $response->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $routeHouseholdNo,
        ]));
        $response->assertSessionHasErrors([
            'household_no' => 'The household number does not match this form URL.',
        ]);

        $afterRoute = DemoHouseholdWaterSupply::find($routeHouseholdNo);
        $afterBody = DemoHouseholdWaterSupply::find($bodyHouseholdNo);

        $this->assertNotNull($afterRoute);
        $this->assertNotNull($afterBody);
        $this->assertSame(1, (int) ($afterRoute['step'] ?? 0));
        $this->assertSame(1, (int) ($afterBody['step'] ?? 0));
        $this->assertNull($afterRoute['microbiological_test_date'] ?? null);
        $this->assertNull($afterRoute['microbiological_result'] ?? null);
        $this->assertNull($afterBody['microbiological_test_date'] ?? null);
        $this->assertNull($afterBody['microbiological_result'] ?? null);
        $this->assertFalse(DemoHouseholdWaterSupply::hasCompletedStep2($routeHouseholdNo));
        $this->assertFalse(DemoHouseholdWaterSupply::hasCompletedStep2($bodyHouseholdNo));
    }

    public function test_blank_step2_persists_completed_step_two_in_mysql(): void
    {
        $householdNo = $this->seedStep1Household('HH-630');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 2,
            'microbiological_test_date' => null,
            'microbiological_result' => null,
            'physicochemical_test_date' => null,
            'physicochemical_result' => null,
        ]);
    }

    public function test_step2_get_prefills_saved_db_values_after_session_field_flush(): void
    {
        $householdNo = $this->seedStep1Household('HH-631');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-15',
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => '2026-07-16',
            'physicochemical_result' => 'failed',
        ])->assertRedirect();

        session([DemoHouseholdWaterSupply::SESSION_KEY => []]);

        $html = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('value="2026-07-15"', $html);
        $this->assertStringContainsString('value="2026-07-16"', $html);
        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'microbiological_result' => 'passed',
            'physicochemical_result' => 'failed',
            'completed_step' => 2,
        ]);
    }

    public function test_repeat_step2_does_not_decrease_completed_step_after_step3(): void
    {
        $householdNo = $this->seedStep1Household('HH-632');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), ['household_no' => $householdNo])->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.step3.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'toilet_type' => 'pour_flush_with_septic_tank',
            'open_defecation_practiced' => 'no',
            'shared_toilet' => 'no',
            'sewage_disposal_method' => 'on_site_safely_managed',
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 3,
        ]);

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-08-01',
            'microbiological_result' => 'passed',
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 3,
        ]);
        $this->assertSame(
            '2026-08-01',
            DemoHouseholdWaterSupply::find($householdNo)['microbiological_test_date'] ?? null
        );
    }

    public function test_forged_step2_fields_cannot_override_server_authority(): void
    {
        $householdNo = $this->seedStep1Household('HH-633');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-15',
            'microbiological_result' => 'passed',
            'completed_step' => 999,
            'household_id' => 99999,
            'id' => 88888,
            'solid_waste_status' => 'good_practice',
            'basic_safe_water_status' => 'without_basic_safe_water',
        ])->assertRedirect();

        $this->assertDatabaseHas('household_environmental_profiles', [
            'household_id' => $household->id,
            'completed_step' => 2,
            'microbiological_result' => 'passed',
        ]);
        $this->assertSame(1, Household::query()->count());
        $this->assertSame(1, \App\Models\HouseholdEnvironmentalProfile::query()->count());
    }

    public function test_soft_deleted_household_cannot_post_step2(): void
    {
        $householdNo = $this->seedStep1Household('HH-634');
        $household = Household::query()->where('household_no', $householdNo)->firstOrFail();
        $this->archiveHouseholdForWizard($household);

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => '2026-07-15',
            'microbiological_result' => 'passed',
        ])->assertSessionHasErrors('household_no');

        $this->assertDatabaseMissing('household_environmental_profiles', [
            'household_id' => $household->id,
            'microbiological_result' => 'passed',
        ]);
    }

    public function test_future_step2_dates_are_rejected_and_empty_dates_remain_optional(): void
    {
        $householdNo = $this->seedStep1Household('HH-640');
        $future = now()->addDay()->toDateString();
        $today = now()->toDateString();
        $past = now()->subDay()->toDateString();

        $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => $future,
            'microbiological_result' => 'passed',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertSessionHasErrors('microbiological_test_date');

        $unchanged = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNull($unchanged['microbiological_test_date'] ?? null);
        $this->assertNull($unchanged['microbiological_result'] ?? null);
        $this->assertSame(1, (int) ($unchanged['step'] ?? 0));

        $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'physicochemical_test_date' => $future,
            'physicochemical_result' => 'failed',
        ])->assertSessionHasErrors('physicochemical_test_date');

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => $today,
            'microbiological_result' => 'passed',
            'physicochemical_test_date' => $past,
            'physicochemical_result' => 'failed',
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));
    }

    public function test_step2_date_inputs_cap_at_today_and_omit_calendar_overlay(): void
    {
        $householdNo = $this->seedStep1Household('HH-641');
        $today = now()->toDateString();

        $html = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('name="microbiological_test_date"', $html);
        $this->assertStringContainsString('name="physicochemical_test_date"', $html);
        $this->assertStringContainsString('max="'.$today.'"', $html);
        $this->assertStringNotContainsString('lml-hws__date-icon', $html);
        $this->assertStringNotContainsString('bi-calendar3', $html);
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
