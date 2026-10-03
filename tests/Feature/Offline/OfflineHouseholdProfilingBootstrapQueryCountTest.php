<?php

namespace Tests\Feature\Offline;

use App\Models\ChildNutrition;
use App\Models\Household;
use App\Models\Resident;
use App\Services\Offline\OfflineHealthSummaryCompiler;
use App\Support\HouseholdProfilingPresenter;
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

    /**
     * Residents are spread across households (two per household) so any per-household query shows up.
     */
    private function seedResidents(int $count): void
    {
        $household = null;
        foreach (range(1, $count) as $i) {
            if ($i % 2 === 1) {
                $household = Household::factory()->create();
            }
            $resident = Resident::factory()->create(['household_id' => $household->getKey()]);
            if ($i % 3 === 0) {
                ChildNutrition::factory()->create(['resident_id' => $resident->getKey()]);
            }
        }
    }

    private function bootstrapQueryCount(): int
    {
        $this->actingAsStaff(StaffRole::BHW);
        // Warm-up: schema lookups are memoized, so only the second call reflects steady-state queries.
        $this->getJson(route('offline.household-profiling-bootstrap'))->assertOk();
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

    public function test_household_level_presentation_without_members_matches_full_presentation(): void
    {
        $this->seedResidents(6);

        foreach (Household::query()->get() as $household) {
            $full = HouseholdProfilingPresenter::fromModel($household);
            $light = HouseholdProfilingPresenter::fromModel($household, withMembers: false);

            $this->assertSame([], $light['memberList']);
            unset($full['memberList'], $light['memberList']);
            $this->assertSame($full, $light);
        }
    }
}
