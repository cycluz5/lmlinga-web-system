<?php

namespace App\Support;

use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetEvent;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Services\ChatbotDatasetPublisher;
use App\Services\ChatbotDatasetWorkflow;
use Illuminate\Support\Carbon;

/**
 * View data for Chatbot Datasets (Admin).
 * Plain strings only; Blade escapes everything (dataset text is shown as preformatted plain text).
 */
final class ChatbotDatasetPresenter
{
    /** Review-only history actions. Dataset review was removed, so these are never shown. */
    private const REVIEW_ACTIONS = ['submitted', 'approved', 'rejected', 'discarded'];

    /**
     * @return array<string, mixed>
     */
    public static function row(ChatbotDataset $dataset): array
    {
        $current = $dataset->currentVersion();
        $approved = $dataset->approvedVersion();
        $retired = $dataset->isRetired();
        $unverified = $approved === null && $current?->machine_translated === true;

        return [
            'id' => $dataset->id,
            'title' => (string) ($current?->title ?? ''),
            'language' => $dataset->language,
            'category' => $dataset->category,
            'language_label' => ChatbotDatasetCatalog::LANGUAGES[$dataset->language] ?? $dataset->language,
            'category_label' => ChatbotDatasetCatalog::CATEGORIES[$dataset->category] ?? $dataset->category,
            'source_name' => $dataset->source_name,
            'is_default' => $dataset->isDefault(),
            'unverified' => $unverified,
            'retired' => $retired,
            'approved' => ! $retired && $approved !== null,
            'updated_at' => self::date($dataset->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ChatbotDataset $dataset): array
    {
        $dataset->loadMissing(['versions.creator', 'events.actor', 'events.version']);
        $approved = $dataset->approvedVersion();
        $retired = $dataset->isRetired();
        $default = $dataset->isDefault();
        $editBlocked = $default ? ChatbotDataset::DEFAULT_LOCKED_MESSAGE : ($retired ? 'Restore this dataset before changing it.' : null);

        return self::row($dataset) + [
            'approved_version' => $approved !== null ? self::version($approved) : null,
            'draft_version' => $unverified_draft = ($approved === null && $dataset->openVersion()?->machine_translated) ? self::version($dataset->openVersion()) : null,
            'history' => $default ? [] : $dataset->events
                ->reject(fn (ChatbotDatasetEvent $e): bool => in_array($e->action, self::REVIEW_ACTIONS, true))
                ->sortByDesc('id')
                ->map(fn (ChatbotDatasetEvent $e): array => self::event($e))
                ->values()
                ->all(),
            'translations' => self::translations($dataset),
            'retired' => $retired,
            'can_edit' => $editBlocked === null,
            'edit_blocked_reason' => $editBlocked,
            'can_retire' => ! $retired && ! $default && $unverified_draft === null,
            'can_restore' => $retired && ! $default,
            'can_verify' => $unverified_draft !== null && ! $retired,
            'can_translate' => $approved !== null && ! $retired,
            // Chatbot health information: is the live text written for the chatbot, and searchable (indexed)?
            'in_chatbot' => ! $retired && $approved !== null && app(ChatbotDatasetPublisher::class)->isWritten($dataset),
            'indexed' => ! $retired && $approved !== null && app(ChatbotDatasetPublisher::class)->isIndexed($dataset),
        ];
    }

    /**
     * The same topic (category + source name) in each language, existing or missing.
     *
     * @return list<array{language: string, label: string, exists: bool, current: bool, id: int|null, title: string|null, retired: bool, unverified: bool}>
     */
    public static function translations(ChatbotDataset $dataset): array
    {
        $found = ChatbotDataset::query()
            ->with('versions')
            ->where('category', $dataset->category)
            ->where('source_name', $dataset->source_name)
            ->get()
            ->keyBy('language');

        $out = [];
        foreach (ChatbotDatasetCatalog::LANGUAGES as $key => $label) {
            $match = $found->get($key);
            $out[] = [
                'language' => $key,
                'label' => $label,
                'exists' => $match !== null,
                'current' => $match?->id === $dataset->id,
                'id' => $match?->id,
                'title' => $match?->currentVersion()?->title,
                'retired' => $match?->isRetired() ?? false,
                'unverified' => $match !== null && $match->approvedVersion() === null && $match->openVersion()?->machine_translated === true,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ChatbotDatasetVersion $v): array
    {
        return [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'title' => $v->title,
            'source_reference' => $v->source_reference,
            'content' => $v->content,
            'size_kb' => number_format($v->size_bytes / 1024, 1),
            'line_count' => $v->line_count,
            'original_filename' => $v->original_filename,
            'warnings' => array_values($v->screening['warnings'] ?? []),
            'no_personal_data_confirmed' => $v->no_personal_data_confirmed,
            'status' => $v->status,
            'status_label' => ChatbotDatasetCatalog::label($v->status),
            'created_by' => self::staffName($v->relationLoaded('creator') ? $v->creator : null, null),
            'created_at' => self::date($v->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(ChatbotDatasetEvent $e): array
    {
        return [
            'action' => $e->action,
            'action_label' => match ($e->action) {
                ChatbotDatasetWorkflow::ACTION_UPLOADED => 'Uploaded',
                ChatbotDatasetWorkflow::ACTION_IMPORTED => 'Imported from the existing corpus',
                ChatbotDatasetWorkflow::ACTION_UPDATED => 'Draft changed',
                ChatbotDatasetWorkflow::ACTION_REVISED => 'New draft version',
                ChatbotDatasetWorkflow::ACTION_PUBLISHED => 'Published to the chatbot',
                ChatbotDatasetWorkflow::ACTION_SUPERSEDED => 'Replaced by newer approved version',
                ChatbotDatasetWorkflow::ACTION_RETIRED => 'Retired',
                ChatbotDatasetWorkflow::ACTION_RESTORED => 'Restored',
                default => ucfirst($e->action),
            },
            'version_no' => $e->relationLoaded('version') ? $e->version?->version_no : null,
            'actor' => self::staffName($e->actor, $e->actor_role),
            'actor_role' => $e->actor_role,
            'note' => $e->note,
            'at' => self::date($e->created_at),
        ];
    }

    private static function staffName(?User $user, ?string $fallbackRole): string
    {
        $name = $user?->composeDisplayName() ?? '';

        return $name !== '' ? $name : ($fallbackRole !== null && $fallbackRole !== '' ? $fallbackRole : 'Staff');
    }

    private static function date(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->copy()->timezone((string) config('app.timezone'))->format('M j, Y g:i A')
            : '';
    }
}
