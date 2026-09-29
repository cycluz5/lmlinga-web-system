<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — timbang_records.
 *
 * - bmi_value was computed by the app from weight_kg and height_cm and stored → a
 *   database-generated column (same idea as maternal_care.bmi). It is now filled for
 *   every measurement with weight and height; the app still shows BMI only for the
 *   age groups it applies to (adolescents and adults).
 * - resident_id was nullable, so a measurement could exist without a person → NOT NULL.
 *
 * The WHO classification columns (weight_for_age, height_for_age, weight_for_height,
 * muac_status, bmi_status, overall_nutritional_status) stay as a record of the result at
 * measurement time, since they also depend on the resident's age and sex.
 */
return new class extends Migration
{
    /** Guards keep strict-mode MySQL from failing on missing or zero height. */
    private const BMI_EXPRESSION = 'CASE WHEN weight_kg > 0 AND height_cm > 0 '
        .'THEN ROUND(weight_kg / ((height_cm / 100.0) * (height_cm / 100.0)), 1) END';

    public function up(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        $orphans = DB::table('timbang_records')->whereNull('resident_id')->pluck('timbang_id');
        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'timbang_records row(s) '.$orphans->implode(', ')
                .' have no resident_id. Link or delete them before running this migration.'
            );
        }

        Schema::table('timbang_records', function (Blueprint $table) {
            $table->unsignedBigInteger('resident_id')->nullable(false)->change();
        });

        if ($this->bmiIsGenerated()) {
            return;
        }

        if (Schema::hasColumn('timbang_records', 'bmi_value')) {
            Schema::table('timbang_records', function (Blueprint $table) {
                $table->dropColumn('bmi_value');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // SQLite can only add VIRTUAL generated columns to an existing table.
            Schema::table('timbang_records', function (Blueprint $table) {
                $table->decimal('bmi_value', 5, 1)->nullable()->virtualAs(self::BMI_EXPRESSION);
            });

            return;
        }

        // Raw DDL: Laravel emits "STORED NULL", which MariaDB 10.4 rejects.
        DB::statement(
            'ALTER TABLE timbang_records ADD COLUMN bmi_value DECIMAL(5,1) AS ('
            .self::BMI_EXPRESSION.') STORED AFTER weight_for_height'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        if ($this->bmiIsGenerated()) {
            // Keep the values the database calculated as plain data again.
            $values = DB::table('timbang_records')->whereNotNull('bmi_value')->pluck('bmi_value', 'timbang_id');

            Schema::table('timbang_records', function (Blueprint $table) {
                $table->dropColumn('bmi_value');
            });
            Schema::table('timbang_records', function (Blueprint $table) {
                $table->decimal('bmi_value', 4, 1)->nullable()->after('weight_for_height');
            });

            foreach ($values as $id => $bmi) {
                DB::table('timbang_records')->where('timbang_id', $id)->update(['bmi_value' => $bmi]);
            }
        }

        Schema::table('timbang_records', function (Blueprint $table) {
            $table->unsignedBigInteger('resident_id')->nullable()->change();
        });
    }

    private function bmiIsGenerated(): bool
    {
        foreach (Schema::getColumns('timbang_records') as $column) {
            if ($column['name'] === 'bmi_value') {
                return ! empty($column['generation']);
            }
        }

        return false;
    }
};
