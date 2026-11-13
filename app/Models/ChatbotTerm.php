<?php

namespace App\Models;

use App\Models\Scopes\StaffNotDeletedScope;
use App\Support\ChatbotTermStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SR-05 Bikol-Iriga term. Content lives in versions; the approved version (if any) is the one in use
 * and stays in use while an edited replacement waits for Linguist review (D-T1).
 *
 * @property int $id
 * @property string $language
 * @property \Illuminate\Support\Carbon|null $retired_at
 * @property int|null $created_by_user_id
 */
class ChatbotTerm extends Model
{
    protected $fillable = ['language', 'retired_at', 'created_by_user_id'];

    protected function casts(): array
    {
        return [
            'retired_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChatbotTermVersion::class)->orderBy('version_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChatbotTermEvent::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id', (new User)->getKeyName())
            ->withoutGlobalScope(StaffNotDeletedScope::class);
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function approvedVersion(): ?ChatbotTermVersion
    {
        return $this->versionsLoaded()->firstWhere('status', ChatbotTermStatus::APPROVED);
    }

    /** The draft, submitted or rejected version, if any (at most one per term). */
    public function openVersion(): ?ChatbotTermVersion
    {
        return $this->versionsLoaded()
            ->filter(fn (ChatbotTermVersion $v): bool => in_array($v->status, ChatbotTermStatus::OPEN, true))
            ->sortByDesc('version_no')
            ->first();
    }

    public function latestVersion(): ?ChatbotTermVersion
    {
        return $this->versionsLoaded()->sortByDesc('version_no')->first();
    }

    /** Version shown in lists: the open one being worked on, else the approved one, else the latest. */
    public function currentVersion(): ?ChatbotTermVersion
    {
        return $this->openVersion() ?? $this->approvedVersion() ?? $this->latestVersion();
    }

    /** Derived term state (no status column, so it can never disagree with the versions). */
    public function displayStatus(): string
    {
        if ($this->isRetired()) {
            return ChatbotTermStatus::RETIRED;
        }
        if ($this->approvedVersion() !== null) {
            return ChatbotTermStatus::APPROVED;
        }

        return $this->openVersion()?->status ?? ChatbotTermStatus::DRAFT;
    }

    /** True while the term has never been submitted (one draft version and nothing else). */
    public function isNeverSubmittedDraft(): bool
    {
        $versions = $this->versionsLoaded();

        return $versions->count() === 1 && $versions->first()->status === ChatbotTermStatus::DRAFT;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ChatbotTermVersion>
     */
    private function versionsLoaded(): \Illuminate\Support\Collection
    {
        return $this->relationLoaded('versions') ? $this->versions : $this->versions()->get();
    }
}
