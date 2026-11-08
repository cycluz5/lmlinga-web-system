<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SR-05 append-only term history. Written only by ChatbotTermWorkflow; never updated.
 *
 * @property int $id
 * @property int $chatbot_term_id
 * @property int|null $chatbot_term_version_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $actor_user_id
 * @property string $actor_role
 * @property string|null $note
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class ChatbotTermEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'chatbot_term_id', 'chatbot_term_version_id', 'action', 'from_status', 'to_status',
        'actor_user_id', 'actor_role', 'note',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ChatbotTermVersion::class, 'chatbot_term_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }
}
