<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Resident;
use App\Support\Database\SchemaLookupCache;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema facade lookups are memoized; DDL and rollbacks invalidate them.
 */
class SchemaLookupCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_column_lookups_hit_the_database_once(): void
    {
        app(SchemaLookupCache::class)->flush();
        $lookups = 0;
        DB::listen(function () use (&$lookups): void {
            $lookups++;
        });

        $this->assertTrue(Schema::hasColumn('residents', 'first_name'));
        $firstLookup = $lookups;
        $this->assertGreaterThan(0, $firstLookup);

        for ($i = 0; $i < 25; $i++) {
            $this->assertTrue(Schema::hasColumn('residents', 'first_name'));
            $this->assertTrue(Schema::hasColumns('residents', ['first_name', 'LAST_NAME']));
            $this->assertFalse(Schema::hasColumn('residents', 'no_such_column'));
        }

        $this->assertSame($firstLookup, $lookups);
    }

    public function test_ddl_invalidates_cached_lookups(): void
    {
        $this->assertFalse(Schema::hasTable('schema_cache_probe'));

        Schema::create('schema_cache_probe', function ($table): void {
            $table->id();
        });
        $this->assertTrue(Schema::hasTable('schema_cache_probe'));
        $this->assertFalse(Schema::hasColumn('schema_cache_probe', 'label'));

        Schema::table('schema_cache_probe', function ($table): void {
            $table->string('label')->nullable();
        });
        $this->assertTrue(Schema::hasColumn('schema_cache_probe', 'label'));

        DB::statement('DROP TABLE schema_cache_probe');
        $this->assertFalse(Schema::hasTable('schema_cache_probe'));
    }

    public function test_rolled_back_ddl_is_not_remembered(): void
    {
        DB::beginTransaction();
        Schema::create('schema_cache_rollback_probe', function ($table): void {
            $table->id();
        });
        $this->assertTrue(Schema::hasTable('schema_cache_rollback_probe'));
        DB::rollBack();

        $this->assertFalse(Schema::hasTable('schema_cache_rollback_probe'));
    }

    public function test_dashboard_stays_within_query_budget(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        Resident::factory()->count(30)->create(['household_id' => $household->id]);

        $this->actingAsStaff(StaffRole::ADMIN);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get(route('dashboard'))->assertOk();

        // Before memoizing schema lookups this page issued ~120 queries per resident.
        $this->assertLessThan(250, $queries);
    }
}
