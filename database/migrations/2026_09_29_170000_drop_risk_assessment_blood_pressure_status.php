<?php

use App\Support\AtRestRecord;
use App\Support\RiskAssessmentErdMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — risk_assessment.blood_pressure_status.
 *
 * The label (Normal / Elevated / Hypertension Stage 1-2 / Hypertensive Crisis) is
 * fully determined by systolic_blood_pressure and diastolic_blood_pressure. Since
 * the at-rest encryption migration it was an app-written encrypted copy, and every
 * screen already recomputes it from the two readings
 * (RiskAssessmentErdMode::bloodPressureStatusLabel), so the stored copy is dropped.
 * With the column gone, RiskAssessmentErdMode::writesBloodPressureStatus() is false
 * and nothing writes it.
 *
 * A database-generated column (pre-encryption schemas) is left alone: it cannot drift.
 * down() re-adds the encrypted column and recomputes it per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('risk_assessment') || ! Schema::hasColumn('risk_assessment', 'blood_pressure_status')) {
            return;
        }

        RiskAssessmentErdMode::resetCachedState();
        if (RiskAssessmentErdMode::isBloodPressureStatusGenerated()) {
            return;
        }

        Schema::table('risk_assessment', function (Blueprint $table) {
            $table->dropColumn('blood_pressure_status');
        });
        RiskAssessmentErdMode::resetCachedState();
    }

    public function down(): void
    {
        if (! Schema::hasTable('risk_assessment') || Schema::hasColumn('risk_assessment', 'blood_pressure_status')) {
            return;
        }

        Schema::table('risk_assessment', function (Blueprint $table) {
            $table->text('blood_pressure_status')->nullable()->after('diastolic_blood_pressure');
        });
        RiskAssessmentErdMode::resetCachedState();

        DB::table('risk_assessment')
            ->select(['risk_assessment_id', 'systolic_blood_pressure', 'diastolic_blood_pressure'])
            ->orderBy('risk_assessment_id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $label = RiskAssessmentErdMode::bloodPressureStatusLabel(
                        AtRestRecord::open($row->systolic_blood_pressure, 'risk_assessment', 'systolic_blood_pressure'),
                        AtRestRecord::open($row->diastolic_blood_pressure, 'risk_assessment', 'diastolic_blood_pressure'),
                    );

                    DB::table('risk_assessment')
                        ->where('risk_assessment_id', $row->risk_assessment_id)
                        ->update([
                            'blood_pressure_status' => AtRestRecord::seal($label, 'risk_assessment', 'blood_pressure_status'),
                        ]);
                }
            }, 'risk_assessment_id');
    }
};
