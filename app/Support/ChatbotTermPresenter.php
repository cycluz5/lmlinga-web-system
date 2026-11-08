<?php

namespace App\Support;

use App\Models\ChatbotTerm;
use App\Models\ChatbotTermEvent;
use App\Models\ChatbotTermVersion;
use App\Models\User;
use App\Services\ChatbotTermWorkflow;
use Illuminate\Support\Carbon;

/**
 * SR-05 view data for Language Management (Admin) and Language Review (Linguist).
 * Returns plain strings only; Blade escapes everything on output.
 */
final class ChatbotTermPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ChatbotTerm $term): array
    {
        $current = $term->currentVersion();
        $open = $term->openVersion();
        $approved = $term->approvedVersion();
        $retired = $term->isRetired();

        $filters = [];
        if ($retired) {
            $filters[] = ChatbotTermStatus::RETIRED;
        } else {
            if ($approved !== null) {
                $filters[] = ChatbotTermStatus::APPROVED;
            }
            if ($open !== null) {
                $filters[] = $open->status;
            }
        }

        return [
            'id' => $term->id,
            'term' => (string) ($current?->term ?? ''),
            'english_meaning' => (string) ($current?->english_meaning ?? ''),
            'status' => $term->displayStatus(),
            'status_label' => ChatbotTermStatus::label($term->displayStatus()),
            'revision_label' => ! $retired && $approved !== null && $open !== null
                ? 'Revision: '.ChatbotTermStatus::label($open->status)
                : null,
            'in_use' => ! $retired && $approved !== null,
            'filters' => $filters,
            'updated_at' => self::date($term->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(ChatbotTerm $term): array
    {
        $term->loadMissing(['versions.creator', 'events.actor', 'events.version']);
        $open = $term->openVersion();
        $approved = $term->approvedVersion();
        $retired = $term->isRetired();
        $submitted = $open?->status === ChatbotTermStatus::SUBMITTED;

        $editBlocked = match (true) {
            $retired => 'Restore this term before editing it.',
            $submitted => 'This term is waiting for Linguist review and cannot be edited now.',
            default => null,
        };

        return self::row($term) + [
            'approved' => $approved !== null ? self::version($approved) : null,
            'open' => $open !== null ? self::version($open) : null,
            'versions' => $term->versions->sortByDesc('version_no')->map(fn (ChatbotTermVersion $v): array => self::version($v))->values()->all(),
            'history' => $term->events->sortByDesc('id')->map(fn (ChatbotTermEvent $e): array => self::event($e))->values()->all(),
            'retired' => $retired,
            'can_edit' => $editBlocked === null,
            'edit_blocked_reason' => $editBlocked,
            'edit_creates_revision' => ! $retired && $open?->status !== ChatbotTermStatus::DRAFT,
            'can_submit' => ! $retired && $open?->status === ChatbotTermStatus::DRAFT,
            'can_retire' => ! $retired && ! $submitted,
            'can_restore' => $retired,
            'can_delete' => $open?->status === ChatbotTermStatus::DRAFT,
            'deletes_whole_term' => $term->isNeverSubmittedDraft(),
        ];
    }

    /**
     * Linguist review page for one submitted version.
     *
     * @return array<string, mixed>
     */
    public static function reviewItem(ChatbotTermVersion $version): array
    {
        $term = $version->chatbotTerm;
        $term->loadMissing(['versions', 'events.actor']);
        $approved = $term->approvedVersion();

        return [
            'version_id' => $version->id,
            'version' => self::version($version),
            'is_revision' => $approved !== null,
            'approved' => $approved !== null ? self::version($approved) : null,
            'pending' => $version->status === ChatbotTermStatus::SUBMITTED && ! $term->isRetired(),
            'history' => $term->events->sortByDesc('id')
                ->filter(fn (ChatbotTermEvent $e): bool => in_array($e->action, [
                    ChatbotTermWorkflow::ACTION_SUBMITTED, ChatbotTermWorkflow::ACTION_APPROVED, ChatbotTermWorkflow::ACTION_REJECTED,
                ], true))
                ->map(fn (ChatbotTermEvent $e): array => self::event($e))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function queueItem(ChatbotTermVersion $version, bool $isRevision): array
    {
        return [
            'version_id' => $version->id,
            'term' => $version->term,
            'english_meaning' => $version->english_meaning,
            'is_revision' => $isRevision,
            'submitted_at' => self::date($version->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function version(ChatbotTermVersion $v): array
    {
        return $v->content() + [
            'id' => $v->id,
            'version_no' => $v->version_no,
            'status' => $v->status,
            'status_label' => ChatbotTermStatus::label($v->status),
            'part_of_speech_label' => $v->part_of_speech !== null ? (ChatbotTermStatus::PARTS_OF_SPEECH[$v->part_of_speech] ?? $v->part_of_speech) : null,
            'created_by' => self::staffName($v->relationLoaded('creator') ? $v->creator : null, null),
            'created_at' => self::date($v->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function event(ChatbotTermEvent $e): array
    {
        return [
            'action' => $e->action,
            'action_label' => match ($e->action) {
                ChatbotTermWorkflow::ACTION_CREATED => 'Created',
                ChatbotTermWorkflow::ACTION_UPDATED => 'Draft edited',
                ChatbotTermWorkflow::ACTION_REVISED => 'New draft version',
                ChatbotTermWorkflow::ACTION_SUBMITTED => 'Submitted for review',
                ChatbotTermWorkflow::ACTION_APPROVED => 'Approved',
                ChatbotTermWorkflow::ACTION_REJECTED => 'Rejected',
                ChatbotTermWorkflow::ACTION_SUPERSEDED => 'Replaced by newer approved version',
                ChatbotTermWorkflow::ACTION_RETIRED => 'Retired',
                ChatbotTermWorkflow::ACTION_RESTORED => 'Restored as draft',
                ChatbotTermWorkflow::ACTION_DISCARDED => 'Unsubmitted draft deleted',
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
        if (! $value instanceof Carbon) {
            return '';
        }

        return $value->copy()->timezone((string) config('app.timezone'))->format('M j, Y g:i A');
    }
}
