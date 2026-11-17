<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SR-05 content of one version of a term. Only a draft is editable; once submitted, the text is fixed
 * (a later edit creates a new version), so every reviewed version keeps exactly what was reviewed.
 *
 * @property int $id
 * @property int $chatbot_term_id
 * @property int $version_no
 * @property string $term
 * @property string $english_meaning
 * @property string|null $tagalog_meaning
 * @property string|null $part_of_speech
 * @property string|null $example_usage
 * @property string|null $notes
 * @property string $status
 * @property int|null $created_by_user_id
 */
class ChatbotTermVersion extends Model
{
    public const CONTENT_FIELDS = ['term', 'english_meaning', 'tagalog_meaning', 'part_of_speech', 'example_usage', 'notes'];

    protected $fillable = [
        'chatbot_term_id', 'version_no', 'term', 'english_meaning', 'tagalog_meaning', 'part_of_speech',
        'example_usage', 'notes', 'status', 'created_by_user_id',
    ];

    public function chatbotTerm(): BelongsTo
    {
        return $this->belongsTo(ChatbotTerm::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    /**
     * @return array<string, string|null>
     */
    public function content(): array
    {
        return $this->only(self::CONTENT_FIELDS);
    }
}
