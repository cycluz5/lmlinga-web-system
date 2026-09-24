<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentPasswordResetToken extends Model
{
    public $timestamps = false;

    protected $table = 'resident_password_resets';

    protected $primaryKey = 'reset_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'reset_token',
        'requested_at',
        'expires_at',
        'is_used',
        'used_at',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'reset_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
            'is_used' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ResidentAccount, $this>
     */
    public function residentAccount(): BelongsTo
    {
        // Owner key follows ResidentAccount::getKeyName() (target ERD mode).
        return $this->belongsTo(ResidentAccount::class, 'account_id');
    }
}
