<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resident-based, age-adaptive Nutritional Status — additive computed-assessment
 * columns for timbang_records. Server-computed only; never user-writable.
 *
 * weight_for_age / height_for_age stay on the existing paper-ERD ENUM columns
 * (created by 2026_09_12_100000_create_timbang_records_table). The columns added
 * here have no legacy ERD shape, so they are plain nullable strings/decimal —
 * not a drop/rename, additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        Schema::table('timbang_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('timbang_records', 'muac_status')) {
                $table->string('muac_status', 60)->nullable()->after('muac_cm');
            }
            if (! Schema::hasColumn('timbang_records', 'bmi_value')) {
                $table->decimal('bmi_value', 4, 1)->nullable()->after('weight_for_height');
            }
            if (! Schema::hasColumn('timbang_records', 'bmi_status')) {
                $table->string('bmi_status', 40)->nullable()->after('bmi_value');
            }
            if (! Schema::hasColumn('timbang_records', 'overall_nutritional_status')) {
                $table->string('overall_nutritional_status', 40)->nullable()->after('bmi_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('timbang_records')) {
            return;
        }

        Schema::table('timbang_records', function (Blueprint $table): void {
            foreach (['muac_status', 'bmi_value', 'bmi_status', 'overall_nutritional_status'] as $column) {
                if (Schema::hasColumn('timbang_records', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
