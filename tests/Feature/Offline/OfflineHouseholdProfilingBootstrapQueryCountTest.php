<?php

namespace Tests\Feature\Offline;

use App\Models\Household;
use App\Models\Resident;
use App\Services\Offline\OfflineHealthSummaryCompiler;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The bootstrap used to run ~12 queries per resident, which exceeded Cloudflare's
 * 120 s origin timeout (HTTP 524) on a populated database.
 */
class OfflineHouseholdProfilingBootstrapQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function seedResidents(int $count): void
    {
        $household = Household::factory()->create();
        Resident::factory()->count($count)->create(['household_id' => $household->getKey()]);
    }

    private function bootstrapQueryCount(): int
    {
        $this->actingAsStaff(StaffRole::BHW);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(route('offline.household-profiling-bootstrap'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_query_count_does_not_grow_with_resident_count(): void
    {
        $this->seedResidents(5);
        $small = $this->bootstrapQueryCount();

        $this->seedResidents(20);
        $large = $this->bootstrapQueryCount();

        $this->assertSame($small, $large, "Bootstrap ran {$small} queries for 5 residents but {$large} for 25.");
    }

    public function test_preloaded_health_summary_matches_per_resident_queries(): void
    {
        $this->seedResidents(4);
        $residents = Resident::query()->get();
        $compiler = app(OfflineHealthSummaryCompiler::class);

        $expected = $residents->map(fn (Resident $r) => $compiler->forResident($r))->all();

        $compiler->preload($residents);
        $actual = $residents->map(fn (Resident $r) => $compiler->forResident($r))->all();
        $compiler->clearPreload();

        $this->assertSame($expected, $actual);
    }
}
