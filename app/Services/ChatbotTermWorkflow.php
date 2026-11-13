<?php

namespace App\Services;

use App\Exceptions\ChatbotTermWorkflowException;
use App\Models\ChatbotTerm;
use App\Models\ChatbotTermEvent;
use App\Models\ChatbotTermVersion;
use App\Models\User;
use App\Support\ApprovedBikolTerms;
use App\Support\ChatbotTermStatus;
use App\Support\StaffRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SR-05 term workflow. Every status change goes through here: role check, status precondition under a
 * row lock, the change, and one history event, all in one transaction.
 *
 * Admin: create, edit (draft in place; otherwise a new draft version), submit, retire, restore, delete a
 * never-submitted draft. Linguist: approve / reject a submitted version (D-7: Admin never approves;
 * D-T2: Linguist never edits). The approved version stays in use until a newer version is approved
 * (D-T1). Reviewed versions are never edited or deleted (D-T5).
 */
class ChatbotTermWorkflow
{
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_REVISED = 'revised';

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_RETIRED = 'retired';

    public const ACTION_RESTORED = 'restored';

    public const ACTION_DISCARDED = 'discarded';

    public const ACTION_SUPERSEDED = 'superseded';

    /**
     * @param  array<string, string|null>  $content
     */
    public function create(User $admin, array $content): ChatbotTerm
    {
        $this->assertAdmin($admin);
        $content = $this->cleanContent($content);

        return DB::transaction(function () use ($admin, $content): ChatbotTerm {
            $this->assertNotDuplicate($content, null);

            $term = ChatbotTerm::query()->create([
                'language' => ChatbotTermStatus::LANGUAGE_BIKOL,
                'created_by_user_id' => $admin->getKey(),
            ]);
            $version = $term->versions()->create($content + [
                'version_no' => 1,
                'status' => ChatbotTermStatus::DRAFT,
                'created_by_user_id' => $admin->getKey(),
            ]);
            $this->log($term, $version, self::ACTION_CREATED, null, ChatbotTermStatus::DRAFT, $admin);

            return $term;
        });
    }

    /**
     * Edit a term. A draft is edited in place; a rejected or approved term gets a new draft version
     * (the reviewed version is kept unchanged, and an approved one stays in use).
     *
     * @param  array<string, string|null>  $content
     */
    public function saveDraft(User $admin, ChatbotTerm $term, array $content): ChatbotTermVersion
    {
        $this->assertAdmin($admin);
        $content = $this->cleanContent($content);

        return DB::transaction(function () use ($admin, $term, $content): ChatbotTermVersion {
            $term = $this->lock($term);
            if ($term->isRetired()) {
                throw new ChatbotTermWorkflowException('Restore this term before editing it.');
            }
            $this->assertNotDuplicate($content, $term->id);

            $open = $term->openVersion();
            if ($open?->status === ChatbotTermStatus::SUBMITTED) {
                throw new ChatbotTermWorkflowException('This term is waiting for Linguist review and cannot be edited now.');
            }

            if ($open?->status === ChatbotTermStatus::DRAFT) {
                $open->fill($content)->save();
                $this->log($term, $open, self::ACTION_UPDATED, ChatbotTermStatus::DRAFT, ChatbotTermStatus::DRAFT, $admin);

                return $open;
            }

            $version = $term->versions()->create($content + [
                'version_no' => (int) $term->versions()->max('version_no') + 1,
                'status' => ChatbotTermStatus::DRAFT,
                'created_by_user_id' => $admin->getKey(),
            ]);
            $this->log($term, $version, self::ACTION_REVISED, null, ChatbotTermStatus::DRAFT, $admin);

            return $version;
        });
    }

    public function submit(User $admin, ChatbotTerm $term): ChatbotTermVersion
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $term): ChatbotTermVersion {
            $term = $this->lock($term);
            $draft = $term->openVersion();
            if ($term->isRetired() || $draft?->status !== ChatbotTermStatus::DRAFT) {
                throw new ChatbotTermWorkflowException('Only a draft can be submitted for review.');
            }
            $approved = $term->approvedVersion();
            if ($approved !== null && $this->sameContent($approved->content(), $draft->content())) {
                throw new ChatbotTermWorkflowException('This draft is the same as the approved version. Change it before submitting.');
            }

            $draft->update(['status' => ChatbotTermStatus::SUBMITTED]);
            $this->log($term, $draft, self::ACTION_SUBMITTED, ChatbotTermStatus::DRAFT, ChatbotTermStatus::SUBMITTED, $admin);

            return $draft;
        });
    }

    public function approve(User $linguist, ChatbotTermVersion $version, ?string $note = null): ChatbotTermVersion
    {
        $this->assertReviewer($linguist, $version);

        return DB::transaction(function () use ($linguist, $version, $note): ChatbotTermVersion {
            $term = $this->lock($version->chatbotTerm);
            $version = $term->versions->firstWhere('id', $version->id);
            if ($term->isRetired() || $version?->status !== ChatbotTermStatus::SUBMITTED) {
                throw new ChatbotTermWorkflowException('This term is no longer waiting for review.');
            }

            $previous = $term->approvedVersion();
            if ($previous !== null) {
                $previous->update(['status' => ChatbotTermStatus::SUPERSEDED]);
                $this->log($term, $previous, self::ACTION_SUPERSEDED, ChatbotTermStatus::APPROVED, ChatbotTermStatus::SUPERSEDED, $linguist);
            }
            $version->update(['status' => ChatbotTermStatus::APPROVED]);
            $this->log($term, $version, self::ACTION_APPROVED, ChatbotTermStatus::SUBMITTED, ChatbotTermStatus::APPROVED, $linguist, $this->cleanNote($note));
            $term->touch();
            DB::afterCommit(fn () => ApprovedBikolTerms::forget());

            return $version;
        });
    }

    public function reject(User $linguist, ChatbotTermVersion $version, string $note): ChatbotTermVersion
    {
        $this->assertReviewer($linguist, $version);
        $note = $this->cleanNote($note);
        if ($note === null) {
            throw new ChatbotTermWorkflowException('Explain what needs to change before rejecting.');
        }

        return DB::transaction(function () use ($linguist, $version, $note): ChatbotTermVersion {
            $term = $this->lock($version->chatbotTerm);
            $version = $term->versions->firstWhere('id', $version->id);
            if ($term->isRetired() || $version?->status !== ChatbotTermStatus::SUBMITTED) {
                throw new ChatbotTermWorkflowException('This term is no longer waiting for review.');
            }

            $version->update(['status' => ChatbotTermStatus::REJECTED]);
            $this->log($term, $version, self::ACTION_REJECTED, ChatbotTermStatus::SUBMITTED, ChatbotTermStatus::REJECTED, $linguist, $note);

            return $version;
        });
    }

    /** Take the term out of use. Its approved version becomes superseded; nothing is deleted. */
    public function retire(User $admin, ChatbotTerm $term): ChatbotTerm
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $term): ChatbotTerm {
            $term = $this->lock($term);
            if ($term->isRetired()) {
                throw new ChatbotTermWorkflowException('This term is already retired.');
            }
            if ($term->openVersion()?->status === ChatbotTermStatus::SUBMITTED) {
                throw new ChatbotTermWorkflowException('This term is waiting for Linguist review and cannot be retired now.');
            }

            $approved = $term->approvedVersion();
            if ($approved !== null) {
                $approved->update(['status' => ChatbotTermStatus::SUPERSEDED]);
            }
            $term->update(['retired_at' => now()]);
            $this->log($term, $approved, self::ACTION_RETIRED, $approved !== null ? ChatbotTermStatus::APPROVED : null, ChatbotTermStatus::RETIRED, $admin);
            DB::afterCommit(fn () => ApprovedBikolTerms::forget());

            return $term;
        });
    }

    /** Bring a retired term back as a draft (copy of its latest version); it must be reviewed again. */
    public function restore(User $admin, ChatbotTerm $term): ChatbotTerm
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $term): ChatbotTerm {
            $term = $this->lock($term);
            if (! $term->isRetired()) {
                throw new ChatbotTermWorkflowException('Only a retired term can be restored.');
            }

            $term->update(['retired_at' => null]);
            $draft = $term->openVersion();
            if ($draft === null || $draft->status !== ChatbotTermStatus::DRAFT) {
                $source = $draft ?? $term->latestVersion();
                $draft = $term->versions()->create($source->content() + [
                    'version_no' => (int) $term->versions()->max('version_no') + 1,
                    'status' => ChatbotTermStatus::DRAFT,
                    'created_by_user_id' => $admin->getKey(),
                ]);
            }
            $this->log($term, $draft, self::ACTION_RESTORED, ChatbotTermStatus::RETIRED, ChatbotTermStatus::DRAFT, $admin);

            return $term;
        });
    }

    /**
     * Permanently delete a draft that was never submitted (D-T5). A brand-new term is deleted entirely;
     * an unsubmitted revision of an existing term is discarded and the term's reviewed history is kept.
     *
     * @return bool true when the whole term was deleted
     */
    public function deleteDraft(User $admin, ChatbotTerm $term): bool
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $term): bool {
            $term = $this->lock($term);
            $draft = $term->openVersion();
            if ($draft?->status !== ChatbotTermStatus::DRAFT) {
                throw new ChatbotTermWorkflowException('Only a draft that was never submitted can be deleted. Retire reviewed terms instead.');
            }

            if ($term->isNeverSubmittedDraft()) {
                $term->delete();

                return true;
            }

            $draft->delete();
            $this->log($term, null, self::ACTION_DISCARDED, ChatbotTermStatus::DRAFT, null, $admin, 'Unsubmitted draft version '.$draft->version_no.' deleted.');

            return false;
        });
    }

    private function lock(ChatbotTerm $term): ChatbotTerm
    {
        $locked = ChatbotTerm::query()->whereKey($term->getKey())->lockForUpdate()->first();
        if ($locked === null) {
            throw new ChatbotTermWorkflowException('This term no longer exists.');
        }
        $locked->load('versions');

        return $locked;
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isActive() || StaffRole::normalize($user->role) !== StaffRole::ADMIN) {
            throw new ChatbotTermWorkflowException('Only an active Admin can manage terms.', 403);
        }
    }

    private function assertReviewer(User $user, ChatbotTermVersion $version): void
    {
        if (! Gate::forUser($user)->allows('review-language-content')) {
            throw new ChatbotTermWorkflowException('Only an active Linguist can review terms.', 403);
        }
        if ((string) $version->created_by_user_id === (string) $user->getKey()) {
            throw new ChatbotTermWorkflowException('You cannot review a term you wrote.', 403);
        }
    }

    /**
     * Refuse the same term with the same English meaning as another term (D-T4), compared without case
     * or extra spaces, against each other term's current and approved wording (retired ones included,
     * so they are restored instead of duplicated).
     *
     * @param  array<string, string|null>  $content
     */
    private function assertNotDuplicate(array $content, ?int $exceptTermId): void
    {
        $key = $this->matchKey($content['term']).'|'.$this->matchKey($content['english_meaning']);
        $candidates = ChatbotTermVersion::query()
            ->whereRaw('LOWER(term) = ?', [mb_strtolower($content['term'], 'UTF-8')])
            ->when($exceptTermId !== null, fn ($q) => $q->where('chatbot_term_id', '!=', $exceptTermId))
            ->whereIn('status', [...ChatbotTermStatus::OPEN, ChatbotTermStatus::APPROVED, ChatbotTermStatus::SUPERSEDED])
            ->get(['chatbot_term_id', 'term', 'english_meaning']);

        foreach ($candidates as $candidate) {
            if ($this->matchKey($candidate->term).'|'.$this->matchKey($candidate->english_meaning) === $key) {
                throw new ChatbotTermWorkflowException('A term with the same wording and English meaning already exists.', 422, 'term');
            }
        }
    }

    private function matchKey(?string $value): string
    {
        return mb_strtolower($this->collapse((string) $value), 'UTF-8');
    }

    /**
     * @param  array<string, string|null>  $a
     * @param  array<string, string|null>  $b
     */
    private function sameContent(array $a, array $b): bool
    {
        foreach (ChatbotTermVersion::CONTENT_FIELDS as $field) {
            if ((string) ($a[$field] ?? '') !== (string) ($b[$field] ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, string|null>
     */
    private function cleanContent(array $content): array
    {
        $clean = [];
        foreach (ChatbotTermVersion::CONTENT_FIELDS as $field) {
            $value = $content[$field] ?? null;
            $value = $value === null ? '' : (string) $value;
            $value = in_array($field, ['example_usage', 'notes'], true)
                ? trim(str_replace(["\r\n", "\r"], "\n", $value))
                : $this->collapse($value);
            $clean[$field] = $value === '' ? null : $value;
        }
        if ($clean['term'] === null || $clean['english_meaning'] === null) {
            throw new ChatbotTermWorkflowException('The term and its English meaning are required.', 422, 'term');
        }

        return $clean;
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", (string) $note));

        return $note === '' ? null : $note;
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function log(
        ChatbotTerm $term,
        ?ChatbotTermVersion $version,
        string $action,
        ?string $from,
        ?string $to,
        User $actor,
        ?string $note = null,
    ): void {
        ChatbotTermEvent::query()->create([
            'chatbot_term_id' => $term->id,
            'chatbot_term_version_id' => $version?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->getKey(),
            'actor_role' => StaffRole::label($actor->role) ?: 'Staff',
            'note' => $note,
        ]);
    }
}
