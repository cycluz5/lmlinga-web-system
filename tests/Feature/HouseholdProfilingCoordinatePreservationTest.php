<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Services\HouseholdService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R02-B — Household coordinate preserve-on-omit + pair consistency.
 */
class HouseholdProfilingCoordinatePreservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shellPayload(array $overrides = []): array
    {
        return array_merge([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Coordinate St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Coordinate St.',
            'accomplished_by' => 'Coord BHW',
        ], $overrides);
    }

    private function seededPlottedHousehold(string $householdNo = 'HH-850'): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Old St.',
            'date_registered' => '2026-01-01',
            'address' => 'Before Edit',
            'latitude' => '13.38220000',
            'longitude' => '123.43120000',
            'accomplished_by' => 'Before',
        ]);
    }

    public function test_update_omitting_both_coordinates_preserves_existing_pair(): void
    {
        $household = $this->seededPlottedHousehold('HH-851');

        $this->put(
            route('household-profiling.update', ['householdNo' => 'HH-851']),
            $this->shellPayload([
                'zone' => 'Zone 5',
                'street' => 'Updated St.',
                'accomplished_by' => 'After',
                'address' => 'After Edit',
            ])
        )->assertRedirect(route('household-profiling.view', ['householdNo' => 'HH-851']));

        $household->refresh();
        $this->assertSame('Zone 5', $household->zone);
        $this->assertSame('Updated St.', $household->street);
        $this->assertSame('After', $household->accomplished_by);
        $this->assertSame('After Edit', $household->address);
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_service_update_omitting_both_coordinates_preserves_existing_pair(): void
    {
        $household = $this->seededPlottedHousehold('HH-852');

        app(HouseholdService::class)->update($household, $this->shellPayload([
            'zone' => 'Zone 3',
            'street' => 'Service St.',
        ]));

        $household->refresh();
        $this->assertSame('Zone 3', $household->zone);
        $this->assertSame('Service St.', $household->street);
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_update_with_valid_coordinate_pair_updates_both(): void
    {
        $household = $this->seededPlottedHousehold('HH-853');

        $this->put(
            route('household-profiling.update', ['householdNo' => 'HH-853']),
            $this->shellPayload([
                'latitude' => '13.39000000',
                'longitude' => '123.44000000',
            ])
        )->assertRedirect();

        $household->refresh();
        $this->assertSame('13.39000000', (string) $household->latitude);
        $this->assertSame('123.44000000', (string) $household->longitude);
    }

    public function test_update_with_explicit_null_pair_clears_both_coordinates(): void
    {
        $household = $this->seededPlottedHousehold('HH-854');

        $this->put(
            route('household-profiling.update', ['householdNo' => 'HH-854']),
            $this->shellPayload([
                'latitude' => null,
                'longitude' => null,
            ])
        )->assertRedirect();

        $household->refresh();
        $this->assertNull($household->latitude);
        $this->assertNull($household->longitude);
    }

    public function test_update_with_explicit_blank_pair_clears_both_coordinates(): void
    {
        $household = $this->seededPlottedHousehold('HH-855');

        $this->put(
            route('household-profiling.update', ['householdNo' => 'HH-855']),
            $this->shellPayload([
                'latitude' => '',
                'longitude' => '',
            ])
        )->assertRedirect();

        $household->refresh();
        $this->assertNull($household->latitude);
        $this->assertNull($household->longitude);
    }

    public function test_update_with_latitude_only_is_rejected_and_preserves_pair(): void
    {
        $household = $this->seededPlottedHousehold('HH-856');

        $this->from(route('household-profiling.edit', ['householdNo' => 'HH-856']))
            ->put(
                route('household-profiling.update', ['householdNo' => 'HH-856']),
                $this->shellPayload([
                    'latitude' => '13.39000000',
                ])
            )
            ->assertRedirect(route('household-profiling.edit', ['householdNo' => 'HH-856']))
            ->assertSessionHasErrors(['longitude']);

        $household->refresh();
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_update_with_longitude_only_is_rejected_and_preserves_pair(): void
    {
        $household = $this->seededPlottedHousehold('HH-857');

        $this->from(route('household-profiling.edit', ['householdNo' => 'HH-857']))
            ->put(
                route('household-profiling.update', ['householdNo' => 'HH-857']),
                $this->shellPayload([
                    'longitude' => '123.44000000',
                ])
            )
            ->assertRedirect(route('household-profiling.edit', ['householdNo' => 'HH-857']))
            ->assertSessionHasErrors(['latitude']);

        $household->refresh();
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_update_with_numeric_latitude_and_null_longitude_is_rejected(): void
    {
        $household = $this->seededPlottedHousehold('HH-858');

        $this->from(route('household-profiling.edit', ['householdNo' => 'HH-858']))
            ->put(
                route('household-profiling.update', ['householdNo' => 'HH-858']),
                $this->shellPayload([
                    'latitude' => '13.39000000',
                    'longitude' => null,
                ])
            )
            ->assertRedirect()
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $household->refresh();
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_update_with_null_latitude_and_numeric_longitude_is_rejected(): void
    {
        $household = $this->seededPlottedHousehold('HH-859');

        $this->from(route('household-profiling.edit', ['householdNo' => 'HH-859']))
            ->put(
                route('household-profiling.update', ['householdNo' => 'HH-859']),
                $this->shellPayload([
                    'latitude' => null,
                    'longitude' => '123.44000000',
                ])
            )
            ->assertRedirect()
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $household->refresh();
        $this->assertSame('13.38220000', (string) $household->latitude);
        $this->assertSame('123.43120000', (string) $household->longitude);
    }

    public function test_create_without_coordinates_persists_unplotted_sentinel_pair(): void
    {
        $this->post(route('household-profiling.store'), $this->shellPayload([
            'street' => 'Create Pending St.',
        ]))->assertRedirect();

        $household = Household::query()->where('street', 'Create Pending St.')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);
    }

    public function test_create_with_valid_coordinate_pair_persists_both(): void
    {
        $this->post(route('household-profiling.store'), $this->shellPayload([
            'street' => 'Create Plotted St.',
            'latitude' => '13.38110000',
            'longitude' => '123.43060000',
        ]))->assertRedirect();

        $household = Household::query()->where('street', 'Create Plotted St.')->firstOrFail();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }

    public function test_spot_mapping_coordinate_update_still_updates_both(): void
    {
        $household = $this->seededPlottedHousehold('HH-860');

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-860',
            'lat' => 13.39110000,
            'lng' => 123.44110000,
            'consent' => true,
            'confirm_replot' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.39110000', (string) $household->latitude);
        $this->assertSame('123.44110000', (string) $household->longitude);
    }

    public function test_r01c_pending_create_then_spot_mapping_plot_still_works(): void
    {
        $response = $this->post(route('household-profiling.store'), array_merge(
            $this->shellPayload([
                'street' => 'R01C Pending St.',
            ]),
            ['from' => 'spot-mapping']
        ));

        $household = Household::query()->where('street', 'R01C Pending St.')->firstOrFail();
        $this->assertEquals(0.0, (float) $household->latitude);
        $this->assertEquals(0.0, (float) $household->longitude);

        $response->assertRedirect(route('spot-mapping.index', [
            'plot_household' => $household->household_no,
        ]));

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('data-stat="pending">1</p>', false);

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => $household->household_no,
            'lat' => 13.38110000,
            'lng' => 123.43060000,
            'consent' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);

        $this->get(route('spot-mapping.index'))
            ->assertOk()
            ->assertSee('data-stat="plotted">1</p>', false)
            ->assertSee('data-stat="pending">0</p>', false);
    }
}
