<?php

namespace App\Support;

use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetEvent;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Services\ChatbotDatasetWorkflow;
use Illuminate\Support\Carbon;

/**
 * SR-07 view data for Chatbot Datasets (Admin) and Language Review (Linguist).
 * Plain strings only; Blade escapes everything (dataset text is shown as preformatted plain text).
 */
final class ChatbotDatasetPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ChatbotDataset $dataset): array
    {
        $current = $dataset->currentVersion();
        $open = $dataset->openVersion();
        $approved = $dataset->approvedVersion();
        $retired = $dataset->isRetired();

        $filters = [];
        if ($retired) {
            $filters[] = ChatbotDatasetCatalog::RETIRED;
        } else {
            if ($approved !== null) {
                $filters[] = ChatbotDatasetCatalog::APPROVED;
            }
            if ($open !== null) {
                $filters[] = $open->status;
            }
        }

        return [
            'id' => $dataset->id,
            'title' => (string) ($current?->title ?? ''),
            'language' => $dataset->language,
            'language_label' => ChatbotDatasetCatalog::LANGUAGES[$dataset->language] ?? $dataset->language,
            'category_label' => ChatbotDatasetCatalog::CATEGORIES[$dataset->category] ?? $dataset->category,
            'source_name' => $dataset->source_name,
            'status' => $dataset->displayStatus(),
            'status_label' => ChatbotDatasetCatalog::label($dataset->displayStatus()),
            'revision_label' => ! $retired && $approved !== null && $open !== null
                ? 'Replacement: '.ChatbotDatasetCatalog::label($open->status)
                : null,
            'approved' => ! $retired && $approved !== null,
            'filters' => $filters,
            'updated_at' => self::date($dataset->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ChatbotDataset $dataset): array
    {
        $dataset->loadMissing(['versions.creator', 'events.actor', 'events.version']);
        $open = $dataset->openVersion();
        $approved = $dataset->approvedVersion();
        $retired = $dataset->isRetired();
        $submitted = $open?->status === ChatbotDatasetCatalog::SUBMITTED;

        $editBlocked = match (true) {
            $retired => 'Restore this dataset before changing it.',
            $submitted => 'This dataset is waiting for Linguist review and cannot be changed now.',
            default => null,
        };

        return self::row($dataset) + [
            'approved_version' => $approved !== null ? self::version($approved) : null,
            'open_version' => $open !== null ? self::version($open) : null,
            'history' => $dataset->events->sortByDesc('id')->map(fn (ChatbotDatasetEvent $e): array => self::event($e))->values()->all(),
            'retired' => $retired,
            'can_edit' => $editBlocked === null,
            'edit_blocked_reason' => $editBlocked,
            'edit_creates_revision' => ! $retired && $open?->status !== ChatbotDatasetCatalog::DRAFT,
            'can_submit' => ! $retired && $open?->status === ChatbotDatasetCatalog::DRAFT,
            'can_retire' => ! $retired && ! $submitted,
            'can_restore' => $retired,
            'can_delete' => $open?->status === ChatbotDatasetCatalog::DRAFT,
            'deletes_whole_dataset' => $dataset->isNeverSubmittedDraft(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function reviewItem(ChatbotDatasetVersion $version): array
    {
        $dataset = $version->chatbotDataset;
        $dataset->loadMissing(['versions', 'events.actor', 'events.version']);
        $approved = $dataset->approvedVersion();

        return [
            'version_id' => $version->id,
            'dataset' => self::row($dataset),
            'version' => self::version($version),
            'is_revision' => $approved !== null && $approved->id !== $version->id,
            'approved' => $approved !== null && $approved->id !== $version->id ? self::version($approved) : null,
            'pending' => $version->status === ChatbotDatasetCatalog::SUBMITTED && ! $dataset->isRetired(),
            'history' => $dataset->events->sortByDesc('id')
                ->filter(fn (ChatbotDatasetEvent $e): bool => in_array($e->action, [
                    ChatbotDatasetWorkflow::ACTION_SUBMITTED, ChatbotDatasetWorkflow::ACTION_APPROVED, ChatbotDatasetWorkflow::ACTION_REJECTED,
                ], true))
                ->map(fn (ChatbotDatasetEvent $e): array => self::event($e))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function queueItem(ChatbotDatasetVersion $version, string $language, string $category, bool $isRevision): array
    {
        return [
            'version_id' => $version->id,
            'title' => $version->title,
            'language_label' => ChatbotDatasetCatalog::LANGUAGES[$language] ?? $language,
            'category_label' => ChatbotDatasetCatalog::CATEGORIES[$category] ?? $category,
            'warning_count' => count($version->screening['warnings'] ?? []),
            'is_revision' => $isRevision,
            'submitted_at' => self::date($version->updated_at),
        ];
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
                ChatbotDatasetWorkflow::ACTION_SUBMITTED => 'Submitted for review',
                ChatbotDatasetWorkflow::ACTION_APPROVED => 'Approved',
                ChatbotDatasetWorkflow::ACTION_REJECTED => 'Rejected',
                ChatbotDatasetWorkflow::ACTION_SUPERSEDED => 'Replaced by newer approved version',
                ChatbotDatasetWorkflow::ACTION_RETIRED => 'Retired',
                ChatbotDatasetWorkflow::ACTION_RESTORED => 'Restored as draft',
                ChatbotDatasetWorkflow::ACTION_DISCARDED => 'Unsubmitted draft deleted',
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
