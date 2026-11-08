<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spot Mapping "Plot New Household" creates the Head with only the basic identity
 * fields; the socio-economic profile is completed later in Household Profiling.
 * The original residents migration declared these columns NOT NULL, so every plot
 * failed on MySQL with "Field 'occupation' doesn't have a default value".
 */
return new class extends Migration
{
    /** @var array<string, int> column => length */
    private const COLUMNS = [
        'occupation' => 100,
        'monthly_income' => 50,
        'religion' => 100,
        'education' => 100,
        'fp_user' => 8,
    ];

    public function up(): void
    {
        // The 3NF schema (database/schema/mysql-schema.sql) keys residents by resident_id
        // and already stores these fields as nullable FKs/enums; never retype them.
        if (! Schema::hasTable('residents') || Schema::hasColumn('residents', 'resident_id')) {
            return;
        }

        $columns = array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('residents', $column),
            ARRAY_FILTER_USE_KEY
        );

        if ($columns === []) {
            return;
        }

        Schema::table('residents', function (Blueprint $table) use ($columns) {
            foreach ($columns as $column => $length) {
                $table->string($column, $length)->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // Intentionally left nullable: reverting would break Heads plotted before their profile is completed.
    }
};
