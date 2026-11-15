<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Rules\WithinBarangayBoundary;
use App\Support\Geo\BarangayBoundary;
use App\Support\Offline\OfflineOperationType;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesStaff;
use Tests\Support\InteractsWithOfflineSync;
use Tests\TestCase;

/**
 * Batch 5 (panel refinement SR-11 / SM-03) — server-side Spot Mapping geofence using the existing La Medalla
 * polygon (resources/geo/la-medalla-boundary.geojson, unchanged). Every location entry point is covered:
 * plot existing household, plot new household, phone handoff, household create/edit, offline sync.
 */
class SpotMappingBoundaryEnforcementTest extends TestCase
{
    use AuthenticatesStaff;
    use InteractsWithOfflineSync;
    use RefreshDatabase;

    private const INSIDE = ['lat' => 13.3811, 'lng' => 123.4306];

    /** Well outside the polygon (north-west of La Medalla). */
    private const OUTSIDE = ['lat' => 13.47453212, 'lng' => 123.37216972];

    /** Just outside the north-east edge of the outline. */
    private const JUST_OUTSIDE = ['lat' => 13.3855, 'lng' => 123.4345];

    protected function tearDown(): void
    {
        BarangayBoundary::flushCache();
        parent::tearDown();
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function vertex(): array
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/fixtures/spot-mapping/boundary-check-points.json')), true);
        $point = collect($fixture['points'])->firstWhere('label', 'vertex 0');

        return ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
    }

    private function unplotted(string $householdNo): Household
    {
        return Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    private function plot(string $householdNo, array $point, array $extra = [])
    {
        return $this->postJson(route('spot-mapping.plot'), array_merge(
            ['household_no' => $householdNo, 'consent' => true],
            $point,
            $extra
        ));
    }

    // ------------------------------------------------------------------ plotting an existing household

    public function test_point_inside_the_polygon_is_saved(): void
    {
        $this->unplotted('HH-501');
        $this->actingAsStaff(StaffRole::BHW);

        $this->plot('HH-501', self::INSIDE)->assertOk();

        $this->assertEqualsWithDelta(13.3811, (float) Household::where('household_no', 'HH-501')->value('latitude'), 1e-7);
    }

    public function test_point_outside_the_polygon_is_rejected_with_a_clear_message_and_nothing_is_saved(): void
    {
        $this->unplotted('HH-502');
        $this->actingAsStaff(StaffRole::BHW);

        foreach ([self::OUTSIDE, self::JUST_OUTSIDE] as $point) {
            $this->plot('HH-502', $point)
                ->assertStatus(422)
                ->assertJsonPath('message', WithinBarangayBoundary::OUTSIDE_MESSAGE)
                ->assertJsonValidationErrors(['lat' => WithinBarangayBoundary::OUTSIDE_MESSAGE]);
        }

        $this->assertNull(Household::where('household_no', 'HH-502')->value('latitude'));
    }

    public function test_point_exactly_on_the_boundary_counts_as_inside(): void
    {
        $vertex = $this->vertex();
        $this->assertTrue(BarangayBoundary::load()->contains($vertex['lat'], $vertex['lng']));
        $this->unplotted('HH-503');
        $this->actingAsStaff(StaffRole::BHW);

        $this->plot('HH-503', $vertex)->assertOk();
    }

    public function test_missing_or_invalid_coordinates_get_the_existing_validation_messages(): void
    {
        $this->unplotted('HH-504');
        $this->actingAsStaff(StaffRole::BHW);

        $this->postJson(route('spot-mapping.plot'), ['household_no' => 'HH-504', 'consent' => true])
            ->assertStatus(422)->assertJsonValidationErrors(['lat' => 'Plot coordinates are required.']);

        $this->plot('HH-504', ['lat' => 'abc', 'lng' => 'xyz'])
            ->assertStatus(422)->assertJsonValidationErrors(['lat', 'lng']);

        $this->plot('HH-504', ['lat' => 95, 'lng' => 123.4306])
            ->assertStatus(422)->assertJsonValidationErrors(['lat' => 'Latitude must be between -90 and 90.']);

        $this->assertNull(Household::where('household_no', 'HH-504')->value('latitude'));
    }

    public function test_unauthenticated_and_linguist_requests_cannot_update_a_location(): void
    {
        $this->unplotted('HH-505');

        $guest = $this->plot('HH-505', self::INSIDE);
        $this->assertContains($guest->getStatusCode(), [302, 401]);

        $this->actingAsStaff(StaffRole::LINGUIST);
        $linguist = $this->plot('HH-505', self::INSIDE);
        $this->assertContains($linguist->getStatusCode(), [302, 403]);

        $this->assertNull(Household::where('household_no', 'HH-505')->value('latitude'));
    }

    // ------------------------------------------------------------------ other entry points

    public function test_new_household_plot_outside_is_rejected_and_not_created(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->postJson(route('spot-mapping.plot-new'), $this->plotHouseholdPayload(array_merge(['household_no' => '611'], self::OUTSIDE)))
            ->assertStatus(422)
            ->assertJsonPath('message', WithinBarangayBoundary::OUTSIDE_MESSAGE);

        $this->assertDatabaseMissing('households', ['household_no' => '611']);
    }

    public function test_phone_handoff_outside_is_rejected(): void
    {
        $this->unplotted('HH-506');
        $this->actingAsStaff(StaffRole::BHW);

        $this->postJson(route('spot-mapping.plot-handoff'), array_merge([
            'household_no' => 'HH-506',
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'HHTS',
            'zone' => '2',
            'consent' => true,
            'client_marker_id' => 'client-marker-batch5',
        ], self::OUTSIDE))
            ->assertStatus(422)
            ->assertJsonPath('message', WithinBarangayBoundary::OUTSIDE_MESSAGE);
    }

    public function test_household_create_with_an_outside_location_is_rejected(): void
    {
        $this->actingAsStaff(StaffRole::BHW);

        $this->post(route('household-profiling.store'), $this->householdCreatePayload([
            'household_no' => '612',
            'latitude' => (string) self::OUTSIDE['lat'],
            'longitude' => (string) self::OUTSIDE['lng'],
        ]))->assertSessionHasErrors(['latitude' => WithinBarangayBoundary::OUTSIDE_MESSAGE]);

        $this->assertDatabaseMissing('households', ['household_no' => '612']);
    }

    public function test_offline_sync_cannot_bypass_the_boundary(): void
    {
        $this->actingAsFieldStaff();

        $response = $this->postOfflineSync($this->offlineEnvelope(
            OfflineOperationType::PLOT_HOUSEHOLD_WITH_HEAD,
            $this->plotHouseholdPayload(array_merge(['household_no' => '613'], self::OUTSIDE)),
        ));

        $this->assertNotSame('SYNCED', $response->json('code'));
        $this->assertStringContainsString(WithinBarangayBoundary::OUTSIDE_MESSAGE, (string) $response->getContent());
        $this->assertDatabaseMissing('households', ['household_no' => '613']);
    }

    // ------------------------------------------------------------------ existing data is preserved

    public function test_existing_household_outside_can_still_be_edited_with_its_location_unchanged(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-507',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => self::OUTSIDE['lat'],
            'longitude' => self::OUTSIDE['lng'],
        ]);
        $this->actingAsStaff(StaffRole::ADMIN);

        // Unchanged (outside) location + other edits: allowed; the stored location is preserved.
        $this->put(route('household-profiling.update', ['householdNo' => 'HH-507']), $this->householdUpdatePayload($household, [
            'date_registered' => '2026-02-01',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $fresh = $household->fresh();
        $this->assertSame('2026-02-01', $fresh->date_registered?->format('Y-m-d'));
        $this->assertEqualsWithDelta(self::OUTSIDE['lat'], (float) $fresh->latitude, 1e-7);

        // Moving it to another outside point: rejected; moving it inside: allowed.
        $this->put(route('household-profiling.update', ['householdNo' => 'HH-507']), $this->householdUpdatePayload($fresh, [
            'latitude' => (string) self::JUST_OUTSIDE['lat'],
            'longitude' => (string) self::JUST_OUTSIDE['lng'],
        ]))->assertSessionHasErrors(['latitude' => WithinBarangayBoundary::OUTSIDE_MESSAGE]);

        $this->put(route('household-profiling.update', ['householdNo' => 'HH-507']), $this->householdUpdatePayload($fresh, [
            'latitude' => (string) self::INSIDE['lat'],
            'longitude' => (string) self::INSIDE['lng'],
        ]))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(self::INSIDE['lat'], (float) $household->fresh()->latitude, 1e-7);
    }

    public function test_existing_markers_outside_the_polygon_are_still_shown_on_the_map(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-508',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => self::OUTSIDE['lat'],
            'longitude' => self::OUTSIDE['lng'],
        ]);
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->get(route('spot-mapping.index'))->assertOk()->assertSee('HH-508', false);
    }

    // ------------------------------------------------------------------ fail closed and rollback

    public function test_missing_boundary_fails_closed(): void
    {
        config(['lmlinga.geofence.boundary_path' => base_path('tests/fixtures/spot-mapping/does-not-exist.geojson')]);
        BarangayBoundary::flushCache();
        $this->unplotted('HH-509');
        $this->actingAsStaff(StaffRole::BHW);

        $this->plot('HH-509', self::INSIDE)
            ->assertStatus(422)
            ->assertJsonPath('message', WithinBarangayBoundary::UNAVAILABLE_MESSAGE);
        $this->assertNull(Household::where('household_no', 'HH-509')->value('latitude'));
    }

    public function test_rollback_switch_turns_enforcement_off(): void
    {
        config(['lmlinga.geofence.enforce' => false]);
        $this->unplotted('HH-510');
        $this->actingAsStaff(StaffRole::BHW);

        $this->plot('HH-510', self::OUTSIDE)->assertOk();
    }
}
