<?php

use App\Support\WorkerAssignedZones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1A — Appointment-scoped concurrent assigned zones.
 * Created only when worker_appointments exists. Does not drop assigned_zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('worker_appointments') || Schema::hasTable('worker_appointment_zones')) {
            return;
        }

        $appointmentKey = Schema::hasColumn('worker_appointments', 'appointment_id')
            ? 'appointment_id'
            : 'id';

        Schema::create('worker_appointment_zones', function (Blueprint $table) use ($appointmentKey): void {
            $table->id('worker_appointment_zone_id');
            $table->unsignedBigInteger('appointment_id');
            $table->string('assigned_zone', 20);
            $table->timestamps();

            $table->unique(['appointment_id', 'assigned_zone'], 'uq_worker_appt_zone');
            $table->foreign('appointment_id', 'fk_worker_appt_zones_appt')
                ->references($appointmentKey)
                ->on('worker_appointments')
                ->cascadeOnDelete();
        });

        WorkerAssignedZones::backfillPivotFromScalarAppointments();
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_appointment_zones');
    }
};
