<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Durable idempotency receipt for a successfully applied offline operation.
 * Application infrastructure — not an authoritative ERD model.
 */
class OfflineSyncReceipt extends Model
{
    public const STATUS_APPLIED = 'applied';

    protected $table = 'offline_sync_receipts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operation_id',
        'actor_user_id',
        'operation_type',
        'payload_hash',
        'status',
        'household_pk',
        'resident_pk',
        'household_no',
        'member_no',
        'result_json',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_json' => 'array',
            'actor_user_id' => 'integer',
            'household_pk' => 'integer',
            'resident_pk' => 'integer',
        ];
    }
}
