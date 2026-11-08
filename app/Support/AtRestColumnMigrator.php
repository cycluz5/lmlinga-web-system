<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts AtRestColumns tables between plaintext and ciphertext storage.
 *
 * encrypt(): widen columns to TEXT (MySQL/MariaDB only), then seal each row.
 * decrypt(): open each row, then restore the original column definitions.
 * Both are idempotent: sealed values pass through seal(), plaintext through open().
 */
final class AtRestColumnMigrator
{
    /** @var array<string, string> */
    private const PRIMARY_KEYS = [
        'medical_history' => 'medical_history_id',
        'disability_type' => 'disability_type_id',
        'family_history' => 'fam_history_id',
        'past_medical_history' => 'past_med_id',
        'red_flags_assessment' => 'red_flag_id',
        'visual_screening' => 'visual_screening_id',
        'risk_assessment' => 'risk_assessment_id',
        'death_records' => 'death_record_id',
        'hiv_screening' => 'hiv_screening_id',
        'syphilis_screening' => 'syphilis_screening_id',
        'hepatitis_b_screening' => 'hep_b_screening_id',
    ];

    private const CHUNK = 200;

    public function encryptAll(): void
    {
        foreach (AtRestColumns::tables() as $table) {
            $this->encrypt($table);
        }
    }

    public function decryptAll(): void
    {
        foreach (array_reverse(AtRestColumns::tables()) as $table) {
            $this->decrypt($table);
        }
    }

    public function encrypt(string $table): void
    {
        $columns = $this->presentColumns($table);
        if ($columns === []) {
            return;
        }

        if ($this->isMysql()) {
            if ($table === 'risk_assessment') {
                $this->replaceGeneratedBloodPressureStatus();
            }

            foreach ($columns as $column) {
                $nullability = str_contains((string) AtRestColumns::originalDefinition($table, $column), 'NOT NULL')
                    && AtRestColumns::type($table, $column) === AtRestColumns::TEXT
                    ? 'NOT NULL'
                    : 'NULL';
                DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` TEXT %s', $table, $column, $nullability));
            }
        }

        $this->rewriteRows($table, $columns, function (object $row, string $column) use ($table): ?string {
            if ($table === 'risk_assessment' && $column === 'blood_pressure_status') {
                return AtRestRecord::seal(
                    RiskAssessmentErdMode::bloodPressureStatusLabel(
                        AtRestRecord::open($row->systolic_blood_pressure ?? null, $table, 'systolic_blood_pressure'),
                        AtRestRecord::open($row->diastolic_blood_pressure ?? null, $table, 'diastolic_blood_pressure'),
                    ),
                    $table,
                    $column
                );
            }

            return AtRestRecord::seal($row->{$column}, $table, $column);
        });
    }

    public function decrypt(string $table): void
    {
        $columns = $this->presentColumns($table);
        if ($columns === []) {
            return;
        }

        $this->rewriteRows($table, $columns, function (object $row, string $column) use ($table): ?string {
            $plain = AtRestNarrativeField::open($row->{$column}, $table, $column);

            return AtRestColumns::type($table, $column) === AtRestColumns::BOOL
                ? ((string) $plain === '1' ? '1' : '0')
                : $plain;
        });

        if (! $this->isMysql()) {
            return;
        }

        foreach ($columns as $column) {
            if ($table === 'risk_assessment' && $column === 'blood_pressure_status') {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` %s',
                $table,
                $column,
                AtRestColumns::originalDefinition($table, $column)
            ));
        }

        if ($table === 'risk_assessment' && in_array('blood_pressure_status', $columns, true)) {
            DB::statement('ALTER TABLE `risk_assessment` DROP COLUMN `blood_pressure_status`');
            DB::statement(sprintf(
                'ALTER TABLE `risk_assessment` ADD COLUMN `blood_pressure_status` varchar(30) AS (%s) STORED AFTER `diastolic_blood_pressure`',
                AtRestColumns::BLOOD_PRESSURE_STATUS_EXPRESSION
            ));
            RiskAssessmentErdMode::resetCachedState();
        }
    }

    /**
     * @return list<string>
     */
    public function presentColumns(string $table): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, self::PRIMARY_KEYS[$table] ?? '')) {
            return [];
        }

        return array_values(array_filter(
            AtRestColumns::columns($table),
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));
    }

    public static function primaryKey(string $table): ?string
    {
        return self::PRIMARY_KEYS[$table] ?? null;
    }

    /**
     * MySQL cannot compute a generated column from ciphertext, so the app owns
     * blood_pressure_status from here on (values are recomputed per row).
     */
    private function replaceGeneratedBloodPressureStatus(): void
    {
        RiskAssessmentErdMode::resetCachedState();
        if (! RiskAssessmentErdMode::isBloodPressureStatusGenerated()) {
            return;
        }

        DB::statement('ALTER TABLE `risk_assessment` DROP COLUMN `blood_pressure_status`');
        DB::statement('ALTER TABLE `risk_assessment` ADD COLUMN `blood_pressure_status` TEXT NULL AFTER `diastolic_blood_pressure`');
        RiskAssessmentErdMode::resetCachedState();
    }

    /**
     * @param  list<string>  $columns
     * @param  callable(object, string): ?string  $transform
     */
    private function rewriteRows(string $table, array $columns, callable $transform): void
    {
        $pk = self::PRIMARY_KEYS[$table];
        $select = array_values(array_unique(array_merge([$pk], $columns, $table === 'risk_assessment'
            ? array_values(array_intersect(['systolic_blood_pressure', 'diastolic_blood_pressure'], $columns))
            : [])));

        DB::table($table)->select($select)->orderBy($pk)->chunkById(self::CHUNK, function ($rows) use ($table, $pk, $columns, $transform): void {
            foreach ($rows as $row) {
                $update = [];
                foreach ($columns as $column) {
                    $update[$column] = $transform($row, $column);
                }
                DB::table($table)->where($pk, $row->{$pk})->update($update);
            }
        }, $pk);
    }

    private function isMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
