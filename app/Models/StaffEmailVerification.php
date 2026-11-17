<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Batch 1 — pending email-ownership verification for a staff account.
 * verified_at NULL means the account is not usable yet. code_hash is a bcrypt hash; never the code.
 *
 * @property int $user_id
 * @property string $email
 * @property string|null $code_hash
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property int $attempts
 * @property int $sends_in_window
 * @property \Illuminate\Support\Carbon|null $send_window_started_at
 * @property \Illuminate\Support\Carbon|null $last_sent_at
 * @property \Illuminate\Support\Carbon|null $verified_at
 */
class StaffEmailVerification extends Model
{
    protected $table = 'staff_email_verifications';

    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'expires_at',
        'attempts',
        'sends_in_window',
        'send_window_started_at',
        'last_sent_at',
        'verified_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'attempts' => 'integer',
            'sends_in_window' => 'integer',
            'expires_at' => 'datetime',
            'send_window_started_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
