<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use App\Support\ChatbotDatasetCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Chatbot dataset (identity). Content lives in versions; an Admin save publishes the new version at once.
 *
 * @property int $id
 * @property string $language
 * @property string $category
 * @property string $source_name
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property bool $is_default
 * @property int|null $created_by_user_id
 */
class ChatbotDataset extends Model
{
    public const DEFAULT_LOCKED_MESSAGE = 'This is a default dataset. It cannot be changed or retired.';

    protected $fillable = ['language', 'category', 'source_name', 'is_default', 'retired_at', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime', 'is_default' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChatbotDatasetVersion::class)->orderBy('version_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatbotDatasetEvent::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    public function isDefault(): bool
    {
        return (bool) $this->is_default;
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function approvedVersion(): ?ChatbotDatasetVersion
    {
        return $this->versionsLoaded()->firstWhere('status', ChatbotDatasetCatalog::APPROVED);
    }

    public function openVersion(): ?ChatbotDatasetVersion
    {
        return $this->versionsLoaded()
            ->filter(fn (ChatbotDatasetVersion $v): bool => in_array($v->status, ChatbotDatasetCatalog::OPEN, true))
            ->sortByDesc('version_no')
            ->first();
    }

    public function latestVersion(): ?ChatbotDatasetVersion
    {
        return $this->versionsLoaded()->sortByDesc('version_no')->first();
    }

    public function currentVersion(): ?ChatbotDatasetVersion
    {
        return $this->openVersion() ?? $this->approvedVersion() ?? $this->latestVersion();
    }

    public function displayStatus(): string
    {
        if ($this->isRetired()) {
            return ChatbotDatasetCatalog::RETIRED;
        }
        if ($this->approvedVersion() !== null) {
            return ChatbotDatasetCatalog::APPROVED;
        }

        return $this->openVersion()?->status ?? ChatbotDatasetCatalog::DRAFT;
    }

    /**
     * @return Collection<int, ChatbotDatasetVersion>
     */
    private function versionsLoaded(): Collection
    {
        return $this->relationLoaded('versions') ? $this->versions : $this->versions()->get();
    }
}
