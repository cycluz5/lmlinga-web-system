<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — resident_statuses (ERD schema).
 *
 * A resident is Deceased exactly when a Verified death_records row exists for them,
 * so resident_statuses (status + death_record_id) only repeated that fact. On the
 * ERD schema the app already derives vital status from death_records
 * (ResidentVitalStatus) and never writes this table, so it is dropped.
 *
 * Only the ERD shape is dropped (death_records present, legacy death_requests absent,
 * table keyed by death_record_id). The legacy Laravel shape used without
 * death_records keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isErdResidentStatuses()) {
            return;
        }

        // Rows must agree with death_records before the copy is removed.
        $conflicts = DB::table('resident_statuses')
            ->leftJoin('death_records', function ($join) {
                $join->on('death_records.resident_id', '=', 'resident_statuses.resident_id')
                    ->where('death_records.verification_status', '=', 'Verified');
            })
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('resident_statuses.status', 'Deceased')->whereNull('death_records.death_record_id');
                })->orWhere(function ($q) {
                    $q->where('resident_statuses.status', 'Active')->whereNotNull('death_records.death_record_id');
                });
            })
            ->pluck('resident_statuses.resident_id');

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(
                'resident_statuses disagrees with death_records for resident_id(s) '
                .$conflicts->unique()->implode(', ')
                .'. Fix the death record verification (or the status row) before running this migration.'
            );
        }

        Schema::drop('resident_statuses');
    }

    public function down(): void
    {
        if (Schema::hasTable('resident_statuses')
            || ! Schema::hasTable('death_records')
            || Schema::hasTable('death_requests')) {
            return;
        }

        Schema::create('resident_statuses', function (Blueprint $table) {
            $table->id('resident_status_id');
            $table->unsignedBigInteger('resident_id')->unique('uq_resstatus_resident');
            $table->enum('status', ['Active', 'Deceased'])->default('Active')->index('idx_resstatus_status');
            $table->unsignedBigInteger('death_record_id')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            $table->foreign('death_record_id', 'fk_resstatus_death')
                ->references('death_record_id')
                ->on('death_records')
                ->nullOnDelete();
            $table->foreign('resident_id', 'fk_resstatus_resident')
                ->references('resident_id')
                ->on('residents');
        });

        // Rebuild the copy from verified death records.
        foreach (DB::table('death_records')->where('verification_status', 'Verified')->get(['death_record_id', 'resident_id', 'verified_at']) as $record) {
            DB::table('resident_statuses')->insertOrIgnore([
                'resident_id' => $record->resident_id,
                'status' => 'Deceased',
                'death_record_id' => $record->death_record_id,
                'recorded_at' => $record->verified_at ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function isErdResidentStatuses(): bool
    {
        return Schema::hasTable('resident_statuses')
            && Schema::hasTable('death_records')
            && ! Schema::hasTable('death_requests')
            && Schema::hasColumn('resident_statuses', 'death_record_id');
    }
};
