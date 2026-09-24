<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\Household;
use App\Models\Resident;
use App\Services\SpotMappingService;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Spot Mapping — authoritative ERD household coordinate plot persistence.
 */
class SpotMappingErdPlotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private int $erdHouseholdId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provisionErdHouseholdSchema();
        $this->seedErdPendingHousehold();

        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);
        $this->actingAsStaff(StaffRole::BHW);
    }

    private function provisionErdHouseholdSchema(): void
    {
        Schema::dropIfExists('residents');
        Schema::dropIfExists('households');

        Schema::create('households', function ($table): void {
            $table->id('household_id');
            $table->string('household_no', 16)->unique();
            $table->string('purok', 20)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('household_type', 32)->nullable();
            $table->date('date_registered')->nullable();
            $table->timestamps();
        });

        Schema::create('residents', function ($table): void {
            $table->id('resident_id');
            $table->unsignedBigInteger('household_id');
            $table->string('relation_to_household_head', 64)->nullable();
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->timestamps();
        });

        $this->resetResolvedPrimaryKeyCache(Household::class);
        $this->resetResolvedPrimaryKeyCache(Resident::class);
    }

    /**
     * @param  class-string<Household|Resident>  $modelClass
     */
    private function resetResolvedPrimaryKeyCache(string $modelClass): void
    {
        $reflection = new ReflectionClass($modelClass);
        if (! $reflection->hasProperty('resolvedKeyName')) {
            return;
        }

        $property = $reflection->getProperty('resolvedKeyName');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }

    private function seedErdPendingHousehold(): void
    {
        $this->erdHouseholdId = (int) DB::table('households')->insertGetId([
            'household_no' => 'HH-005',
            'purok' => '5',
            'latitude' => null,
            'longitude' => null,
            'household_type' => 'NHTS',
            'date_registered' => '2026-01-15',
            'created_at' => now(),
            'updated_at' => now(),
        ], 'household_id');

        DB::table('residents')->insert([
            'household_id' => $this->erdHouseholdId,
            'relation_to_household_head' => 'Head',
            'first_name' => 'Pedro',
            'middle_name' => 'L.',
            'last_name' => 'Castillo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('households')->insert([
            'household_no' => 'HH-006',
            'purok' => '2',
            'latitude' => 13.38200000,
            'longitude' => 123.43150000,
            'household_type' => 'Non-NHTS',
            'date_registered' => '2026-01-16',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validHandoffPayload(string $householdNo = 'HH-005'): array
    {
        return [
            'household_no' => $householdNo,
            'house_head' => 'Pedro L. Castillo',
            'household_type' => 'NHTS',
            'zone' => '5',
            'lat' => 13.3855,
            'lng' => 123.4345,
            'consent' => true,
        ];
    }

    public function test_existing_erd_household_with_null_coordinates_can_be_plotted(): void
    {
        $before = DB::table('households')->where('household_id', $this->erdHouseholdId)->first();
        $this->assertNull($before->latitude);
        $this->assertNull($before->longitude);

        $response = $this->postJson(route('spot-mapping.plot-handoff'), $this->validHandoffPayload());

        $response->assertOk();
        $response->assertJsonPath('household_no', 'HH-005');
        $response->assertJsonPath('marker.status', 'plotted');
        $response->assertJsonStructure(['handoff_token', 'redirect_url']);

        $token = (string) $response->json('handoff_token');
        $this->assertSame(
            route('environmental-health.household-water-supply', ['handoff' => $token]),
            (string) $response->json('redirect_url')
        );

        $step1 = $this->get((string) $response->json('redirect_url'));
        $step1->assertRedirect(route('environmental-health.household-water-supply', [
            'household' => 'HH-005',
        ]));

        $after = DB::table('households')->where('household_id', $this->erdHouseholdId)->first();
        $this->assertEqualsWithDelta(13.3855, (float) $after->latitude, 0.0000001);
        $this->assertEqualsWithDelta(123.4345, (float) $after->longitude, 0.0000001);
    }

    public function test_household_no_resolves_to_authoritative_household_id_for_coordinate_update(): void
    {
        $result = app(SpotMappingService::class)->updateCoordinates('HH-005', 13.3811, 123.4306);

        $this->assertNotNull($result);
        $this->assertSame($this->erdHouseholdId, (int) $result['household']->getKey());
        $this->assertSame('HH-005', (string) $result['household']->household_no);
    }

    public function test_plot_persists_latitude_and_longitude_without_overwriting_identity_fields(): void
    {
        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'hh-005',
            'lat' => 13.38110000,
            'lng' => 123.43060000,
            'consent' => true,
        ])->assertOk();

        $row = DB::table('households')->where('household_id', $this->erdHouseholdId)->first();

        $this->assertEqualsWithDelta(13.3811, (float) $row->latitude, 0.0000001);
        $this->assertEqualsWithDelta(123.4306, (float) $row->longitude, 0.0000001);
        $this->assertSame('HH-005', (string) $row->household_no);
        $this->assertSame('5', (string) $row->purok);
        $this->assertSame('NHTS', (string) $row->household_type);
    }

    public function test_nonexistent_household_returns_controlled_plot_failure_without_insert(): void
    {
        $before = DB::table('households')->count();

        $response = $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-999',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', SpotMappingService::GENERIC_PLOT_FAILURE);
        $this->assertSame($before, DB::table('households')->count());
        $this->assertDatabaseMissing('households', ['household_no' => 'HH-999']);
    }

    public function test_wrong_household_number_does_not_update_another_row(): void
    {
        $otherId = (int) DB::table('households')->where('household_no', 'HH-006')->value('household_id');

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-404',
            'lat' => 13.9999,
            'lng' => 123.9999,
            'consent' => true,
        ])->assertStatus(422);

        $other = DB::table('households')->where('household_id', $otherId)->first();
        $target = DB::table('households')->where('household_id', $this->erdHouseholdId)->first();

        $this->assertEqualsWithDelta(13.382, (float) $other->latitude, 0.0000001);
        $this->assertNull($target->latitude);
    }

    public function test_existing_map_listing_reads_newly_plotted_household(): void
    {
        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-005',
            'lat' => 13.38110000,
            'lng' => 123.43060000,
            'consent' => true,
        ])->assertOk();

        $markers = app(SpotMappingService::class)->mappedMarkers();
        $marker = collect($markers)->firstWhere('householdNo', 'HH-005');

        $this->assertNotNull($marker);
        $this->assertSame('Pedro L. Castillo', $marker['houseHead']);
        $this->assertSame('5', $marker['zone']);
        $this->assertSame('Zone 5', $marker['zoneLabel']);
        $this->assertSame('plotted', $marker['status']);

        $stats = app(SpotMappingService::class)->stats();
        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['plotted']);
        $this->assertSame(0, $stats['pending']);
    }

    public function test_legacy_id_primary_key_schema_remains_compatible_for_plot(): void
    {
        Schema::dropIfExists('residents');
        Schema::dropIfExists('households');

        Schema::create('households', function ($table): void {
            $table->id();
            $table->string('household_no', 16)->unique();
            $table->string('zone', 32)->nullable();
            $table->string('street', 150)->nullable();
            $table->date('date_registered')->nullable();
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('accomplished_by', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('residents', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->string('relation', 64)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->resetResolvedPrimaryKeyCache(Household::class);
        $this->resetResolvedPrimaryKeyCache(Resident::class);

        $household = Household::factory()->create([
            'household_no' => 'HH-730',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'latitude' => null,
            'longitude' => null,
        ]);

        $this->postJson(route('spot-mapping.plot'), [
            'household_no' => 'HH-730',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
        ])->assertOk();

        $household->refresh();
        $this->assertSame('13.38110000', (string) $household->latitude);
        $this->assertSame('123.43060000', (string) $household->longitude);
    }
}
