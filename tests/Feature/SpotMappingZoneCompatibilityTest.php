<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Services\SpotMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Spot Mapping — ERD purok vs legacy zone marker payload compatibility.
 */
class SpotMappingZoneCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('households', 'purok')) {
            Schema::table('households', function (Blueprint $table): void {
                $table->string('purok', 20)->nullable();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertPlottedHousehold(array $overrides = []): void
    {
        DB::table('households')->insert(array_merge([
            'household_no' => 'HH-ERD-900',
            'zone' => '',
            'street' => 'Test St.',
            'date_registered' => '2026-08-28',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
            'purok' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_erd_purok_numeric_resolves_zone_one(): void
    {
        $this->insertPlottedHousehold([
            'household_no' => 'HH-ERD-Z1',
            'purok' => '1',
        ]);

        $marker = app(SpotMappingService::class)->mappedMarkers()[0];

        $this->assertSame('1', $marker['zone']);
        $this->assertSame('Zone 1', $marker['zoneLabel']);
        $this->assertArrayNotHasKey('purok', $marker);
    }

    public function test_erd_purok_zone_label_resolves_zone_two(): void
    {
        $this->insertPlottedHousehold([
            'household_no' => 'HH-ERD-Z2',
            'purok' => 'Zone 2',
        ]);

        $marker = app(SpotMappingService::class)->mappedMarkers()[0];

        $this->assertSame('2', $marker['zone']);
        $this->assertSame('Zone 2', $marker['zoneLabel']);
    }

    public function test_erd_purok_prefix_resolves_zone_one(): void
    {
        $this->insertPlottedHousehold([
            'household_no' => 'HH-ERD-P1',
            'purok' => 'Purok 1',
        ]);

        $marker = app(SpotMappingService::class)->mappedMarkers()[0];

        $this->assertSame('1', $marker['zone']);
        $this->assertSame('Zone 1', $marker['zoneLabel']);
    }

    public function test_legacy_zone_column_remains_compatible(): void
    {
        Household::factory()->create([
            'household_no' => 'HH-LEG-Z3',
            'zone' => 'Zone 3',
            'latitude' => 13.38110000,
            'longitude' => 123.43060000,
        ]);

        $marker = app(SpotMappingService::class)->mappedMarkers()[0];

        $this->assertSame('3', $marker['zone']);
        $this->assertSame('Zone 3', $marker['zoneLabel']);
    }

    public function test_legacy_zone_preferred_when_both_zone_and_purok_present(): void
    {
        $this->insertPlottedHousehold([
            'household_no' => 'HH-ERD-BOTH',
            'zone' => 'Zone 4',
            'purok' => '2',
        ]);

        $marker = app(SpotMappingService::class)->mappedMarkers()[0];

        $this->assertSame('4', $marker['zone']);
        $this->assertSame('Zone 4', $marker['zoneLabel']);
    }
}
