<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SR-07 one version of a dataset. Only a draft may change; once submitted the content is fixed
 * (a replacement is a new version), so every reviewed version keeps exactly what was reviewed.
 *
 * @property int $id
 * @property int $chatbot_dataset_id
 * @property int $version_no
 * @property string $title
 * @property string $source_reference
 * @property string $content
 * @property string $content_sha256
 * @property int $size_bytes
 * @property int $line_count
 * @property string|null $original_filename
 * @property array<string, mixed>|null $screening
 * @property bool $no_personal_data_confirmed
 * @property string $status
 * @property int|null $created_by_user_id
 */
class ChatbotDatasetVersion extends Model
{
    protected $fillable = [
        'chatbot_dataset_id', 'version_no', 'title', 'source_reference', 'content', 'content_sha256',
        'size_bytes', 'line_count', 'original_filename', 'screening', 'no_personal_data_confirmed', 'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'screening' => 'array',
            'no_personal_data_confirmed' => 'boolean',
        ];
    }

    public function chatbotDataset(): BelongsTo
    {
        return $this->belongsTo(ChatbotDataset::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }
}
