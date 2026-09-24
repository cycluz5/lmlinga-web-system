<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LegacyResidentAccountsSchema;

abstract class TestCase extends BaseTestCase
{
    use \Tests\Support\AuthenticatesStaff;

    /**
     * ClientTestingErdSchema uses Schema::create/drop (SQLite DDL is not rolled
     * back). Force migrate:fresh before the next RefreshDatabase test so Laravel
     * fixtures see residents.id / member_no again.
     */
    protected function beforeRefreshingDatabase()
    {
        if (! Schema::hasTable('residents')) {
            return;
        }

        $erdHarnessShape = Schema::hasColumn('residents', 'resident_id')
            && ! Schema::hasColumn('residents', 'member_no');

        if ($erdHarnessShape) {
            RefreshDatabaseState::$migrated = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        LegacyResidentAccountsSchema::ensure();
        \Tests\Support\LaravelChildImmunizationSqliteCompatibility::ensure();
        \App\Support\UserManagementErdMode::resetCachedState();
        \App\Support\ChildNutritionErdMode::resetCachedState();
        \App\Support\DewormingErdMode::resetCachedState();
        \App\Support\ChildImmunizationErdMode::resetCachedState();
        \App\Support\SchoolImmunizationErdMode::resetCachedState();
        \App\Support\MaternalCareErdMode::resetCachedState();
        \App\Support\NutritionSupplementationErdMode::resetCachedState();
        \App\Support\RiskAssessmentErdMode::resetCachedState();
        \App\Support\RecordRequestErdMode::resetCachedState();
        \App\Support\EnvironmentalSanitationErdMode::resetCachedState();
        \App\Support\FamilyPlanningErdMode::resetCachedState();
        \App\Support\AdultImmunizationErdMode::resetCachedState();
        \App\Support\DeathRecordsErdMode::resetCachedState();
        \App\Models\Household::resetResolvedKeyName();
        \App\Models\Resident::resetResolvedKeyName();
    }

    /**
     * assertDatabaseHas() for tables with AtRestColumns ciphertext: rows are
     * decrypted first, numeric values compare by value ("170.00" == 170).
     *
     * @param  array<string, mixed>  $expected
     */
    protected function assertAtRestDatabaseHas(string $table, array $expected): void
    {
        $this->assertTrue(
            $this->atRestRowsMatching($table, $expected) > 0,
            "Failed asserting that a decrypted row in [{$table}] matches ".json_encode($expected)
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    protected function assertAtRestDatabaseMissing(string $table, array $expected): void
    {
        $this->assertSame(
            0,
            $this->atRestRowsMatching($table, $expected),
            "Failed asserting that no decrypted row in [{$table}] matches ".json_encode($expected)
        );
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function atRestRowsMatching(string $table, array $expected): int
    {
        return \Illuminate\Support\Facades\DB::table($table)->get()
            ->map(static fn (object $row): object => \App\Support\AtRestRecord::openRow($table, $row))
            ->filter(static function (object $row) use ($expected): bool {
                foreach ($expected as $column => $value) {
                    $actual = $row->{$column} ?? null;
                    if ($value === null || $actual === null) {
                        if ($value !== $actual) {
                            return false;
                        }

                        continue;
                    }
                    if (is_bool($value)) {
                        $value = (int) $value;
                    }
                    $same = is_numeric($value) && is_numeric($actual)
                        ? abs((float) $value - (float) $actual) < 0.0001
                        : (string) $value === (string) $actual;
                    if (! $same) {
                        return false;
                    }
                }

                return true;
            })
            ->count();
    }
}
