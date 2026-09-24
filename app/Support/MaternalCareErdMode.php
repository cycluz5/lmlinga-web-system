<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative ERD maternal_care contract helpers.
 */
final class MaternalCareErdMode
{
    public const STATUS_ACTIVE = 'Active';

    public const STATUS_COMPLETED = 'Completed';

    public const STATUS_TRANS_OUT = 'Trans-Out';

    private static ?bool $bmiGenerated = null;

    private static ?bool $persistenceActive = null;

    /**
     * ERD maternal_care is the write/read authority when the JSON
     * maternal_pregnancies table is absent.
     */
    public static function isPersistenceActive(): bool
    {
        if (self::$persistenceActive === null) {
            self::$persistenceActive = ! Schema::hasTable('maternal_pregnancies')
                && Schema::hasTable('maternal_care')
                && Schema::hasColumn('maternal_care', 'resident_id')
                && Schema::hasColumn('maternal_care', 'pregnancy_status');
        }

        return self::$persistenceActive;
    }

    public static function isBmiGenerated(): bool
    {
        if (self::$bmiGenerated === null) {
            self::$bmiGenerated = self::detectBmiGenerated();
        }

        return self::$bmiGenerated;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function filterWritablePayload(array $payload): array
    {
        if (self::isBmiGenerated()) {
            unset($payload['bmi']);
        }

        return $payload;
    }

    public static function resetCachedState(): void
    {
        self::$bmiGenerated = null;
        self::$persistenceActive = null;
    }

    private static function detectBmiGenerated(): bool
    {
        if (! Schema::hasTable('maternal_care') || ! Schema::hasColumn('maternal_care', 'bmi')) {
            return false;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT EXTRA FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?',
                ['maternal_care', 'bmi']
            );

            return $row !== null
                && str_contains(strtoupper((string) ($row->EXTRA ?? '')), 'GENERATED');
        }

        if ($driver === 'sqlite') {
            try {
                foreach (DB::select("PRAGMA table_xinfo('maternal_care')") as $row) {
                    if (($row->name ?? '') === 'bmi' && (int) ($row->hidden ?? 0) !== 0) {
                        return true;
                    }
                }

                $definition = DB::selectOne(
                    "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'maternal_care'"
                );
                $sql = strtoupper((string) ($definition->sql ?? ''));

                return str_contains($sql, '`BMI`') && str_contains($sql, 'GENERATED ALWAYS');
            } catch (\Throwable) {
                return false;
            }
        }

        return false;
    }
}
