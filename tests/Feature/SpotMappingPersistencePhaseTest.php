<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DB18-C — Spot Mapping MySQL map read + coordinate update on existing households.
 */
class SpotMappingPersistencePhaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
        $this->actingAsStaff(StaffRole::BHW);
    }

    /**
     * @return array<string, mixed>
     */
    private function householdAttrs(array $overrides = []): array
    {
        return array_merge([
            'household_no' => 'HH-701',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => null,
            'latitude' => null,
            'longitude' => null,
            'accomplished_by' => null,
        ], $overrides);
    }

    public function test_index_shows_db_stats_and_mapped_markers_only(): void
    {
        $mapped = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-710',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));
        Resident::factory()->create([
            'household_id' => $mapped->id,
            'relation' => 'Head',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'last_name' => 'Reyes',
        ]);
        Resident::factory()->create([
            'household_id' => $mapped->id,
            'relation' => 'Son',
            'first_name' => 'Leo',
            'last_name' => 'Reyes',
        ]);

        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-711',
            'latitude' => null,
            'longitude' => null,
        ]));

        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-712',
            'latitude' => 13.38000000,
            'longitude' => null,
        ]));

        $deleted = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-713',
            'latitude' => 13.37900000,
            'longitude' => 123.43100000,
        ]));
        $deleted->delete();

        $response = $this->get(route('spot-mapping.index'));
        $response->assertOk();
        $response->assertSee('data-stat="total">3</p>', false);
        $response->assertSee('data-stat="plotted">1</p>', false);
        $response->assertSee('data-stat="pending">2</p>', false);
        $response->assertSee('HH-710', false);
        $response->assertSee('Kristine Reyes', false);
        $response->assertDontSee('HH-713', false);

        $mappedNos = array_column(app(SpotMappingService::class)->mappedMarkers(), 'householdNo');
        $this->assertSame(['HH-710'], $mappedNos);
    }

    public function test_stats_invariant_total_equals_plotted_plus_pending(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-720',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-721',
            'latitude' => null,
            'longitude' => null,
        ]));
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-722',
            'latitude' => 13.38,
            'longitude' => null,
        ]));
        $deleted = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-723',
            'latitude' => 13.37,
            'longitude' => 123.43,
        ]));
        $deleted->delete();

        $stats = app(SpotMappingService::class)->stats();

        $this->assertSame(3, $stats['total']);
        $this->assertSame(1, $stats['plotted']);
        $this->assertSame(2, $stats['pending']);
        $this->assertSame($stats['total'], $stats['plotted'] + $stats['pending']);
    }

    public function test_plot_updates_existing_active_household_coordinates(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-730',
        ]));

        $beforeCount = Household::query()->count();

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-730',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertOk();
        $response->assertJsonPath('household_no', 'HH-730');
        $response->assertJsonPath('marker.status', 'plotted');
        $response->assertJsonPath('stats.plotted', 1);

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
        $this->assertSame($beforeCount, Household::query()->count());
    }

    public function test_replot_updates_same_household_row(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-731',
            'latitude' => 13.38000000,
            'longitude' => 123.43000000,
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-731',
            'lat' => 13.3815,
            'lng' => 123.4315,
            'consent' => true,
            'confirm_replot' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38150000', (string) $household->latitude);
        $this->assertSame('123.43150000', (string) $household->longitude);
        $this->assertSame(1, Household::query()->where('household_no', 'HH-731')->count());
    }

    public function test_unknown_household_is_rejected_without_creating_row(): void
    {
        $before = Household::query()->count();

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-999',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $this->assertSame($before, Household::query()->count());
        $this->assertDatabaseMissing('households', ['household_no' => 'HH-999']);
    }

    public function test_soft_deleted_household_is_rejected(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-740',
        ]));
        $household->delete();

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-740',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $this->assertDatabaseMissing('households', ['household_no' => 'HH-740']);
    }

    public function test_invalid_latitude_and_longitude_are_rejected(): void
    {
        Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-750',
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-750',
            'lat' => 91,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['lat']);

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-750',
            'lat' => 13.3811,
            'lng' => 181,
            'consent' => true,
        ])->assertStatus(422)->assertJsonValidationErrors(['lng']);
    }

    public function test_client_surrogate_id_cannot_retarget_another_household(): void
    {
        $target = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-760',
        ]));
        $other = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-761',
            'latitude' => 13.37000000,
            'longitude' => 123.42000000,
        ]));

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-760',
            'id' => $other->id,
            'household_id' => $other->id,
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id', 'household_id']);

        $target->refresh();
        $other->refresh();
        $this->assertNull($target->latitude);
        $this->assertSame('13.37000000', (string) $other->latitude);
    }

    public function test_plot_does_not_write_household_type_or_consent_columns(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-770',
        ]));

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-770',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'household_type' => 'NHTS',
        ])->assertOk();

        $household->refresh();
        $this->assertFalse(Schema::hasColumn('households', 'consent'));
        $this->assertFalse(Schema::hasColumn('households', 'household_type'));
        $this->assertSame('13.38110000', (string) $household->latitude);
    }

    public function test_marker_payload_derives_head_and_member_count(): void
    {
        $household = Household::factory()->create($this->householdAttrs([
            'household_no' => 'HH-780',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]));
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Spouse',
            'first_name' => 'Ana',
            'last_name' => 'Santos',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'relation' => 'Head',
            'first_name' => 'Ben',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        $markers = app(SpotMappingService::class)->mappedMarkers();
        $this->assertCount(1, $markers);
        $this->assertSame('HH-780', $markers[0]['householdNo']);
        $this->assertSame('Ben Cruz Santos', $markers[0]['houseHead']);
        $this->assertSame(2, $markers[0]['members']);
        $this->assertSame('plotted', $markers[0]['status']);
    }
}
