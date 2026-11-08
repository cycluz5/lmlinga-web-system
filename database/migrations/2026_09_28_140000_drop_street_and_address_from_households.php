<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * households.street and households.address are no longer part of the household entity.
 * All readers/writers are schema-guarded (DatabaseSchemaGuard::householdStreetColumn,
 * columnExists), so the Household Profiling form and exports drop them automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('households')) {
            return;
        }

        $columns = array_values(array_filter(
            ['street', 'address'],
            static fn (string $column): bool => Schema::hasColumn('households', $column)
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('households', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('households')) {
            return;
        }

        Schema::table('households', function (Blueprint $table) {
            if (! Schema::hasColumn('households', 'street')) {
                $table->string('street', 150)->nullable()->after('zone');
            }
            if (! Schema::hasColumn('households', 'address')) {
                $table->string('address', 255)->nullable()->after('date_registered');
            }
        });
    }
};
