<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A resident self-registration waiting for its emailed code. payload is encrypted JSON holding
 * the names, zone and the already-hashed password. code_hash is a bcrypt hash; never the code.
 *
 * @property string $email
 * @property array<string, mixed> $payload
 * @property string|null $code_hash
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $attempts
 * @property int $sends_in_window
 * @property \Illuminate\Support\Carbon|null $send_window_started_at
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 */
class ResidentRegistrationVerification extends Model
{
    protected $table = 'resident_registration_verifications';

    protected $fillable = [
        'email',
        'payload',
        'code_hash',
        'expires_at',
        'attempts',
        'sends_in_window',
        'send_window_started_at',
        'last_sent_at',
    ];

    protected $hidden = [
        'code_hash',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'attempts' => 'integer',
            'sends_in_window' => 'integer',
            'expires_at' => 'datetime',
            'send_window_started_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }
}
