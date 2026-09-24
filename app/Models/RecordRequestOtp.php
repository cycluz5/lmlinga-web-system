<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordRequestOtp extends Model
{
    protected $table = 'record_request_otps';

    protected $primaryKey = 'otp_id';

    /**
     * Nothing is mass-assignable from request input.
     * Application code must set attributes explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'code_hash',
        'destination_fingerprint',
    ];

    /**
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'attempt_count' => 'integer',
            'resend_count' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Household-record request this OTP row belongs to.
     * Ownership is the request's account_id, never a browser-supplied OTP field.
     *
     * @return BelongsTo<RecordRequest, $this>
     */
    public function recordRequest(): BelongsTo
    {
        return $this->belongsTo(RecordRequest::class, 'request_id', 'request_id');
    }
}
