<?php

use App\Support\WorkerAssignedZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3NF — worker_appointments.assigned_zone duplicated the first row of
 * worker_appointment_zones. Copy any zone still missing from the zones table,
 * then drop the column; the app derives the primary zone from the zones table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worker_appointments')
            || ! Schema::hasTable('worker_appointment_zones')
            || ! Schema::hasColumn('worker_appointments', 'assigned_zone')) {
            return;
        }

        $appointmentKey = $this->appointmentKey();

        // Only appointments with no zone rows yet; existing zone rows are authoritative.
        $missing = DB::table('worker_appointments')
            ->whereNotNull('assigned_zone')
            ->where('assigned_zone', '!=', '')
            ->whereNotExists(function ($query) use ($appointmentKey) {
                $query->select(DB::raw(1))
                    ->from('worker_appointment_zones')
                    ->whereColumn('worker_appointment_zones.appointment_id', "worker_appointments.{$appointmentKey}");
            })
            ->get([$appointmentKey, 'assigned_zone']);

        foreach ($missing as $appointment) {
            DB::table('worker_appointment_zones')->insert([
                'appointment_id' => $appointment->{$appointmentKey},
                'assigned_zone' => trim((string) $appointment->assigned_zone),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('worker_appointments', function (Blueprint $table) {
            $table->dropColumn('assigned_zone');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('worker_appointments') || Schema::hasColumn('worker_appointments', 'assigned_zone')) {
            return;
        }

        Schema::table('worker_appointments', function (Blueprint $table) {
            $table->string('assigned_zone', 20)->nullable()->after('assigned_barangay');
        });

        if (! Schema::hasTable('worker_appointment_zones')) {
            return;
        }

        $appointmentKey = $this->appointmentKey();
        $firstZones = DB::table('worker_appointment_zones')
            ->orderBy('worker_appointment_zone_id')
            ->get(['appointment_id', 'assigned_zone'])
            ->groupBy('appointment_id');

        foreach ($firstZones as $appointmentId => $rows) {
            DB::table('worker_appointments')
                ->where($appointmentKey, $appointmentId)
                ->update([
                    'assigned_zone' => WorkerAssignedZones::primary($rows->pluck('assigned_zone')->all()),
                ]);
        }
    }

    private function appointmentKey(): string
    {
        return Schema::hasColumn('worker_appointments', 'appointment_id') ? 'appointment_id' : 'id';
    }
};
