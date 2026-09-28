<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spot Mapping "Plot New Household" creates a household before a street is known
 * (street is completed later in Household Profiling), so households.street must
 * accept NULL. The original create migration declared it NOT NULL, which made
 * every plot insert fail on MySQL with "Field 'street' doesn't have a default value".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('households') || ! Schema::hasColumn('households', 'street')) {
            return;
        }

        Schema::table('households', function (Blueprint $table) {
            $table->string('street', 150)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Intentionally left nullable: reverting would break households plotted without a street.
    }
};
