<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecordRequest extends Model
{
    /** @use HasFactory<\Database\Factories\RecordRequestFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'Pending';

    public const STATUS_NO_MATCH = 'No Match';

    public const STATUS_AWAITING_OTP = 'Awaiting OTP';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_DENIED = 'Denied';

    protected $table = 'record_requests';

    protected $primaryKey = 'request_id';

    public $incrementing = true;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'household_no_submitted',
        'zone_submitted',
        'relationship_submitted',
        'first_name_submitted',
        'middle_name_submitted',
        'last_name_submitted',
        'mobile_number_submitted',
        'email_submitted',
        'submitter_ip',
        'matched_resident_id',
        'status',
        'decision_reason',
        'evaluated_at',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evaluated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function matchedResident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'matched_resident_id');
    }

    /**
     * Chatbot account that submitted this household-record request.
     * Owner key follows ResidentAccount::getKeyName() (target ERD mode).
     *
     * @return BelongsTo<ResidentAccount, $this>
     */
    public function residentAccount(): BelongsTo
    {
        return $this->belongsTo(ResidentAccount::class, 'account_id');
    }

    /**
     * OTP storage rows for this request. Resend/verify remain deferred to services.
     *
     * @return HasMany<RecordRequestOtp, $this>
     */
    public function otps(): HasMany
    {
        return $this->hasMany(RecordRequestOtp::class, 'request_id', 'request_id');
    }

    /**
     * Current household Record Request for an account: highest request_id.
     * Historical rows remain stored; only this row drives chatbot workflow UI.
     */
    public static function latestForAccount(int|string $accountId): ?self
    {
        $row = static::query()
            ->where('account_id', $accountId)
            ->orderByDesc('request_id')
            ->first();

        return $row instanceof self ? $row : null;
    }

    /**
     * Whether this row is the current request for its account_id
     * (same definition as latestForAccount).
     */
    public function isCurrentForAccount(): bool
    {
        $latest = static::latestForAccount($this->account_id);

        return $latest instanceof self
            && (int) $latest->request_id === (int) $this->request_id;
    }
}
