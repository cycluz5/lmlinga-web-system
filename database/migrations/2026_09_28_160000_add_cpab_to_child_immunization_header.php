<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CPAB (Child Protected At Birth from neonatal tetanus) on the child immunization header.
 *
 * Birth History collects it, but the 3NF schema has no child_birth_histories
 * table and no CPAB column, so the value was dropped. The paper ERD keeps CPAB
 * on child_immunization; the form allows one of two answers, so one column.
 */
return new class extends Migration
{
    private const VALUES = ['at_least_2_doses_1_month_prior', 'tt3_td3_to_tt5_td5_prior'];

    public function up(): void
    {
        foreach ($this->headerTables() as $table) {
            if (Schema::hasColumn($table, 'cpab')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->enum('cpab', self::VALUES)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->headerTables() as $table) {
            if (Schema::hasColumn($table, 'cpab')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('cpab');
                });
            }
        }
    }

    /**
     * @return list<string>
     */
    private function headerTables(): array
    {
        return array_values(array_filter(
            ['child_immunization', 'child_immunizations'],
            static fn (string $table): bool => Schema::hasTable($table)
        ));
    }
};
