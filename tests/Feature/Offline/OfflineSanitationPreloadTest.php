<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Support\EnvironmentalSanitationErdMode;
use App\Support\EnvironmentalSanitationReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class OfflineSanitationPreloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ClientTestingErdSchema::ensure();
        Household::resetResolvedKeyName();
        Resident::resetResolvedKeyName();
        EnvironmentalSanitationErdMode::resetCachedState();
    }

    public function test_preloaded_presentation_matches_per_household_queries_in_constant_queries(): void
    {
        $households = collect(range(1, 6))->map(function (int $n): Household {
            $id = (int) DB::table('households')->insertGetId([
                'household_no' => sprintf('HH-%03d', $n),
                'purok' => '1',
                'household_type' => 'NHTS',
                'date_registered' => '2026-01-15',
                'created_at' => now(),
                'updated_at' => now(),
            ], 'household_id');

            return Household::query()->findOrFail($id);
        });

        // Only some households have a sanitation row; the rest must come back null.
        foreach ($households->take(3) as $i => $household) {
            DB::table('environmental_sanitation')->insert([
                'household_id' => $household->getKey(),
                'user_id' => 1,
                'toilet_type' => $i === 0 ? 'water-sealed' : 'pit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $service = app(EnvironmentalSanitationReadService::class);
        $expected = $households->map(fn (Household $h) => $service->findPresentationForHousehold($h))->all();

        $service->preloadForHouseholds($households->map->getKey()->all());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $actual = $households->map(fn (Household $h) => $service->findPresentationForHousehold($h))->all();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $service->clearPreload();

        $this->assertSame($expected, $actual);
        $this->assertSame(0, $queries, 'Preloaded lookups should not query per household.');
    }
}
