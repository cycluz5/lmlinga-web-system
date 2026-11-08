<?php

namespace App\Models;

use App\Support\UserManagementErdMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerAppointmentZone extends Model
{
    /**
     * @var string
     */
    protected $table = 'worker_appointment_zones';

    /**
     * @var string
     */
    protected $primaryKey = 'worker_appointment_zone_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'appointment_id',
        'assigned_zone',
    ];

    /**
     * @return BelongsTo<WorkerAppointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(
            WorkerAppointment::class,
            'appointment_id',
            UserManagementErdMode::appointmentKeyName(),
        );
    }
}
