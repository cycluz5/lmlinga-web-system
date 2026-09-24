<?php

namespace Tests\Feature;

use App\Services\HouseholdService;
use App\Support\HouseholdProfilingWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdProfilingLegacyShellWriteCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_schema_still_writes_zone_street_and_optional_columns(): void
    {
        $this->assertFalse(HouseholdProfilingWriteGuard::isHouseholdShellWriteUnsupported());
        $this->assertTrue(Schema::hasColumn('households', 'zone'));
        $this->assertFalse(Schema::hasColumn('households', 'purok'));

        $household = app(HouseholdService::class)->create([
            'household_no' => '121',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
            'address' => '123 Layuan St.',
            'latitude' => '7.12345678',
            'longitude' => '125.12345678',
            'accomplished_by' => 'Maria BHW',
        ]);

        $this->assertDatabaseHas('households', [
            'id' => $household->id,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => '123 Layuan St.',
            'accomplished_by' => 'Maria BHW',
        ]);
        $this->assertSame('2026-01-15', $household->date_registered?->format('Y-m-d'));
    }
}
