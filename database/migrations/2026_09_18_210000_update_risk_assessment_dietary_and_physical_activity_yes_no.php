<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #5 — Risk Assessment dietary_habits / physical_activity become Yes/No.
 *
 * Targets the live ERD table `risk_assessment` (not Laravel `risk_assessments`).
 * No-op when the ERD table is absent. Does not invent Yes/No from old hour labels.
 */
return new class extends Migration
{
    private const TABLE = 'risk_assessment';

    private const OLD_PHYSICAL_LABELS = [
        'More than 2.5hrs/week',
        'Below 2.5hrs/week',
    ];

    private const OLD_DIETARY_LABELS = [
        'Balanced Diet',
        'High Salt',
        'Low Fruits/Vegetables',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        $this->alignDietaryHabits();
        $this->alignPhysicalActivity();
    }

    public function down(): void
    {
        // Not reversible: Yes/No rows cannot be restored to the previous enum domains.
    }

    private function alignDietaryHabits(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'dietary_habits')) {
            return;
        }

        $columnType = $this->mysqlColumnType('dietary_habits');
        if ($columnType === null || $this->isYesNoEnum($columnType)) {
            return;
        }

        if (! $this->isOldDietaryEnum($columnType)) {
            return;
        }

        DB::table(self::TABLE)
            ->whereIn('dietary_habits', self::OLD_DIETARY_LABELS)
            ->update(['dietary_habits' => null]);

        DB::statement("ALTER TABLE risk_assessment MODIFY dietary_habits ENUM('Yes','No') NULL DEFAULT NULL");
    }

    private function alignPhysicalActivity(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'physical_activity')) {
            return;
        }

        $columnType = $this->mysqlColumnType('physical_activity');
        if ($columnType === null || $this->isYesNoEnum($columnType)) {
            return;
        }

        if (! $this->isOldPhysicalEnum($columnType)) {
            return;
        }

        DB::table(self::TABLE)
            ->whereIn('physical_activity', self::OLD_PHYSICAL_LABELS)
            ->update(['physical_activity' => null]);

        DB::statement("ALTER TABLE risk_assessment MODIFY physical_activity ENUM('Yes','No') NULL DEFAULT NULL");
    }

    private function mysqlColumnType(string $column): ?string
    {
        $row = DB::selectOne(
            'SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [self::TABLE, $column]
        );

        if ($row === null) {
            return null;
        }

        $type = trim((string) ($row->COLUMN_TYPE ?? ''));

        return $type === '' ? null : $type;
    }

    private function isYesNoEnum(string $columnType): bool
    {
        return preg_match("/^enum\\(\\s*'Yes'\\s*,\\s*'No'\\s*\\)$/i", $columnType) === 1
            || preg_match("/^enum\\(\\s*'No'\\s*,\\s*'Yes'\\s*\\)$/i", $columnType) === 1;
    }

    private function isOldDietaryEnum(string $columnType): bool
    {
        return str_contains($columnType, 'Balanced Diet')
            && str_contains($columnType, 'High Salt')
            && str_contains($columnType, 'Low Fruits/Vegetables');
    }

    private function isOldPhysicalEnum(string $columnType): bool
    {
        return str_contains($columnType, 'More than 2.5hrs/week')
            || str_contains($columnType, 'Below 2.5hrs/week');
    }
};
