<?php

namespace App\Services;

use App\Exceptions\ChatbotDatasetWorkflowException;
use App\Models\ChatbotDataset;
use App\Models\ChatbotDatasetEvent;
use App\Models\ChatbotDatasetVersion;
use App\Models\User;
use App\Support\ApprovedChatbotDatasets;
use App\Support\ChatbotDatasetCatalog;
use App\Support\StaffRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * SR-07 dataset workflow (same rules as SR-05 terms). Every status change: role check, status precondition
 * under a row lock, the change, one history event, one transaction.
 *
 * Admin: upload, replace (draft in place; otherwise a new draft version), submit, retire, restore, delete a
 * never-submitted draft. Linguist: approve / reject a submitted version — the only way a version becomes
 * Approved. The approved version stays current while a replacement is reviewed. Nothing here indexes or
 * activates a dataset for the chatbot.
 */
class ChatbotDatasetWorkflow
{
    public const ACTION_UPLOADED = 'uploaded';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_REVISED = 'revised';

    public const ACTION_SUBMITTED = 'submitted';

    public const ACTION_APPROVED = 'approved';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_SUPERSEDED = 'superseded';

    public const ACTION_RETIRED = 'retired';

    public const ACTION_RESTORED = 'restored';

    public const ACTION_DISCARDED = 'discarded';

    /**
     * @param  array{title: string, language: string, category: string, source_reference: string, no_personal_data_confirmed: bool, source_name?: string|null, original_filename?: string|null}  $meta
     * @param  array{errors: list<string>, warnings: list<string>, content: string, sha256: string, size_bytes: int, line_count: int, detected_type: string}  $inspection
     */
    public function upload(User $admin, array $meta, array $inspection, string $action = self::ACTION_UPLOADED): ChatbotDataset
    {
        $this->assertAdmin($admin);
        $this->assertInspectionPassed($inspection);
        $meta = $this->cleanMeta($meta);
        if (! array_key_exists($meta['language'], ChatbotDatasetCatalog::LANGUAGES) || ! array_key_exists($meta['category'], ChatbotDatasetCatalog::CATEGORIES)) {
            throw new ChatbotDatasetWorkflowException('Choose a supported language and category.', 422, 'language');
        }
        $sourceName = $meta['source_name'] ?? ChatbotDatasetCatalog::sourceNameFromTitle($meta['title']);
        if (preg_match(ChatbotDatasetCatalog::SOURCE_NAME_PATTERN, $sourceName) !== 1) {
            throw new ChatbotDatasetWorkflowException('The title cannot be turned into a valid source name.', 422, 'title');
        }

        return DB::transaction(function () use ($admin, $meta, $inspection, $sourceName, $action): ChatbotDataset {
            $exists = ChatbotDataset::query()
                ->where('language', $meta['language'])
                ->where('category', $meta['category'])
                ->where('source_name', $sourceName)
                ->exists();
            if ($exists) {
                throw new ChatbotDatasetWorkflowException('A dataset with this title already exists in this language and category. Replace its file instead.', 422, 'title');
            }
            $this->assertNotDuplicate($inspection['sha256'], null);

            $dataset = ChatbotDataset::query()->create([
                'language' => $meta['language'],
                'category' => $meta['category'],
                'source_name' => $sourceName,
                'created_by_user_id' => $admin->getKey(),
            ]);
            $version = $dataset->versions()->create($this->versionAttributes($meta, $inspection, $admin, 1, $meta['language']));
            $this->log($dataset, $version, $action, null, ChatbotDatasetCatalog::DRAFT, $admin);

            return $dataset;
        });
    }

    /**
     * Change title/source reference and/or replace the file. A draft changes in place; a reviewed dataset
     * gets a new draft version (the approved version stays current until the new one is approved).
     *
     * @param  array{title: string, source_reference: string, no_personal_data_confirmed: bool, original_filename?: string|null}  $meta
     * @param  array{errors: list<string>, warnings: list<string>, content: string, sha256: string, size_bytes: int, line_count: int, detected_type: string}|null  $inspection
     */
    public function saveDraft(User $admin, ChatbotDataset $dataset, array $meta, ?array $inspection): ChatbotDatasetVersion
    {
        $this->assertAdmin($admin);
        if ($inspection !== null) {
            $this->assertInspectionPassed($inspection);
        }
        $meta = $this->cleanMeta($meta);

        return DB::transaction(function () use ($admin, $dataset, $meta, $inspection): ChatbotDatasetVersion {
            $dataset = $this->lock($dataset);
            if ($dataset->isRetired()) {
                throw new ChatbotDatasetWorkflowException('Restore this dataset before changing it.');
            }
            $open = $dataset->openVersion();
            if ($open?->status === ChatbotDatasetCatalog::SUBMITTED) {
                throw new ChatbotDatasetWorkflowException('This dataset is waiting for Linguist review and cannot be changed now.');
            }

            $source = $open ?? $dataset->currentVersion();
            $inspection ??= [
                'errors' => [], 'warnings' => $source->screening['warnings'] ?? [], 'content' => $source->content,
                'sha256' => $source->content_sha256, 'size_bytes' => $source->size_bytes, 'line_count' => $source->line_count,
                'detected_type' => $source->screening['detected_type'] ?? '',
            ];
            $meta['original_filename'] ??= $source->original_filename;
            $this->assertNotDuplicate($inspection['sha256'], $dataset->id);

            if ($open?->status === ChatbotDatasetCatalog::DRAFT) {
                $open->fill($this->versionAttributes($meta, $inspection, $admin, $open->version_no, $dataset->language))->save();
                $this->log($dataset, $open, self::ACTION_UPDATED, ChatbotDatasetCatalog::DRAFT, ChatbotDatasetCatalog::DRAFT, $admin);

                return $open;
            }

            $version = $dataset->versions()->create($this->versionAttributes(
                $meta, $inspection, $admin, (int) $dataset->versions()->max('version_no') + 1, $dataset->language
            ));
            $this->log($dataset, $version, self::ACTION_REVISED, null, ChatbotDatasetCatalog::DRAFT, $admin);

            return $version;
        });
    }

    public function submit(User $admin, ChatbotDataset $dataset): ChatbotDatasetVersion
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $dataset): ChatbotDatasetVersion {
            $dataset = $this->lock($dataset);
            $draft = $dataset->openVersion();
            if ($dataset->isRetired() || $draft?->status !== ChatbotDatasetCatalog::DRAFT) {
                throw new ChatbotDatasetWorkflowException('Only a draft can be submitted for review.');
            }
            if (! $draft->no_personal_data_confirmed) {
                throw new ChatbotDatasetWorkflowException('Confirm that the file contains no resident personal information before submitting.');
            }
            $approved = $dataset->approvedVersion();
            if ($approved !== null && $approved->content_sha256 === $draft->content_sha256
                && $approved->title === $draft->title && $approved->source_reference === $draft->source_reference) {
                throw new ChatbotDatasetWorkflowException('This draft is the same as the approved version. Change it before submitting.');
            }

            $draft->update(['status' => ChatbotDatasetCatalog::SUBMITTED]);
            $this->log($dataset, $draft, self::ACTION_SUBMITTED, ChatbotDatasetCatalog::DRAFT, ChatbotDatasetCatalog::SUBMITTED, $admin);

            return $draft;
        });
    }

    public function approve(User $linguist, ChatbotDatasetVersion $version, ?string $note = null): ChatbotDatasetVersion
    {
        $this->assertReviewer($linguist, $version);

        return DB::transaction(function () use ($linguist, $version, $note): ChatbotDatasetVersion {
            $dataset = $this->lock($version->chatbotDataset);
            $version = $dataset->versions->firstWhere('id', $version->id);
            if ($dataset->isRetired() || $version?->status !== ChatbotDatasetCatalog::SUBMITTED) {
                throw new ChatbotDatasetWorkflowException('This dataset is no longer waiting for review.');
            }

            $previous = $dataset->approvedVersion();
            if ($previous !== null) {
                $previous->update(['status' => ChatbotDatasetCatalog::SUPERSEDED]);
                $this->log($dataset, $previous, self::ACTION_SUPERSEDED, ChatbotDatasetCatalog::APPROVED, ChatbotDatasetCatalog::SUPERSEDED, $linguist);
            }
            $version->update(['status' => ChatbotDatasetCatalog::APPROVED]);
            $this->log($dataset, $version, self::ACTION_APPROVED, ChatbotDatasetCatalog::SUBMITTED, ChatbotDatasetCatalog::APPROVED, $linguist, $this->cleanNote($note));
            $dataset->touch();
            DB::afterCommit(fn () => ApprovedChatbotDatasets::forget());

            return $version;
        });
    }

    public function reject(User $linguist, ChatbotDatasetVersion $version, string $note): ChatbotDatasetVersion
    {
        $this->assertReviewer($linguist, $version);
        $note = $this->cleanNote($note);
        if ($note === null) {
            throw new ChatbotDatasetWorkflowException('Explain what needs to change before rejecting.');
        }

        return DB::transaction(function () use ($linguist, $version, $note): ChatbotDatasetVersion {
            $dataset = $this->lock($version->chatbotDataset);
            $version = $dataset->versions->firstWhere('id', $version->id);
            if ($dataset->isRetired() || $version?->status !== ChatbotDatasetCatalog::SUBMITTED) {
                throw new ChatbotDatasetWorkflowException('This dataset is no longer waiting for review.');
            }

            $version->update(['status' => ChatbotDatasetCatalog::REJECTED]);
            $this->log($dataset, $version, self::ACTION_REJECTED, ChatbotDatasetCatalog::SUBMITTED, ChatbotDatasetCatalog::REJECTED, $linguist, $note);

            return $version;
        });
    }

    public function retire(User $admin, ChatbotDataset $dataset): ChatbotDataset
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $dataset): ChatbotDataset {
            $dataset = $this->lock($dataset);
            if ($dataset->isRetired()) {
                throw new ChatbotDatasetWorkflowException('This dataset is already retired.');
            }
            if ($dataset->openVersion()?->status === ChatbotDatasetCatalog::SUBMITTED) {
                throw new ChatbotDatasetWorkflowException('This dataset is waiting for Linguist review and cannot be retired now.');
            }

            $approved = $dataset->approvedVersion();
            $approved?->update(['status' => ChatbotDatasetCatalog::SUPERSEDED]);
            $dataset->update(['retired_at' => now()]);
            $this->log($dataset, $approved, self::ACTION_RETIRED, $approved !== null ? ChatbotDatasetCatalog::APPROVED : null, ChatbotDatasetCatalog::RETIRED, $admin);
            DB::afterCommit(fn () => ApprovedChatbotDatasets::forget());

            return $dataset;
        });
    }

    public function restore(User $admin, ChatbotDataset $dataset): ChatbotDataset
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $dataset): ChatbotDataset {
            $dataset = $this->lock($dataset);
            if (! $dataset->isRetired()) {
                throw new ChatbotDatasetWorkflowException('Only a retired dataset can be restored.');
            }

            $dataset->update(['retired_at' => null]);
            $draft = $dataset->openVersion();
            if ($draft === null || $draft->status !== ChatbotDatasetCatalog::DRAFT) {
                $source = $draft ?? $dataset->latestVersion();
                $draft = $dataset->versions()->create($source->only([
                    'title', 'source_reference', 'content', 'content_sha256', 'size_bytes', 'line_count', 'original_filename',
                    'screening', 'no_personal_data_confirmed',
                ]) + [
                    'version_no' => (int) $dataset->versions()->max('version_no') + 1,
                    'status' => ChatbotDatasetCatalog::DRAFT,
                    'created_by_user_id' => $admin->getKey(),
                ]);
            }
            $this->log($dataset, $draft, self::ACTION_RESTORED, ChatbotDatasetCatalog::RETIRED, ChatbotDatasetCatalog::DRAFT, $admin);

            return $dataset;
        });
    }

    /**
     * Permanently delete only a draft that was never submitted. A brand-new dataset is deleted entirely;
     * an unsubmitted replacement of a reviewed dataset is discarded and the reviewed history is kept.
     *
     * @return bool true when the whole dataset was deleted
     */
    public function deleteDraft(User $admin, ChatbotDataset $dataset): bool
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $dataset): bool {
            $dataset = $this->lock($dataset);
            $draft = $dataset->openVersion();
            if ($draft?->status !== ChatbotDatasetCatalog::DRAFT) {
                throw new ChatbotDatasetWorkflowException('Only a draft that was never submitted can be deleted. Retire reviewed datasets instead.');
            }

            if ($dataset->isNeverSubmittedDraft()) {
                $dataset->delete();

                return true;
            }

            $draft->delete();
            $this->log($dataset, null, self::ACTION_DISCARDED, ChatbotDatasetCatalog::DRAFT, null, $admin, 'Unsubmitted draft version '.$draft->version_no.' deleted.');

            return false;
        });
    }

    private function lock(ChatbotDataset $dataset): ChatbotDataset
    {
        $locked = ChatbotDataset::query()->whereKey($dataset->getKey())->lockForUpdate()->first();
        if ($locked === null) {
            throw new ChatbotDatasetWorkflowException('This dataset no longer exists.');
        }
        $locked->load('versions');

        return $locked;
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isActive() || StaffRole::normalize($user->role) !== StaffRole::ADMIN) {
            throw new ChatbotDatasetWorkflowException('Only an active Admin can manage datasets.', 403);
        }
    }

    private function assertReviewer(User $user, ChatbotDatasetVersion $version): void
    {
        if (! Gate::forUser($user)->allows('review-language-content')) {
            throw new ChatbotDatasetWorkflowException('Only an active Linguist can review datasets.', 403);
        }
        if ((string) $version->created_by_user_id === (string) $user->getKey()) {
            throw new ChatbotDatasetWorkflowException('You cannot review a dataset you uploaded.', 403);
        }
    }

    /**
     * @param  array{errors: list<string>}  $inspection
     */
    private function assertInspectionPassed(array $inspection): void
    {
        if ($inspection['errors'] !== []) {
            throw new ChatbotDatasetWorkflowException(implode(' ', $inspection['errors']), 422, 'dataset_file');
        }
    }

    /** Identical content may not exist in another dataset (any reviewable or reviewed version). */
    private function assertNotDuplicate(string $sha256, ?int $exceptDatasetId): void
    {
        $duplicate = ChatbotDatasetVersion::query()
            ->where('content_sha256', $sha256)
            ->when($exceptDatasetId !== null, fn ($q) => $q->where('chatbot_dataset_id', '!=', $exceptDatasetId))
            ->exists();
        if ($duplicate) {
            throw new ChatbotDatasetWorkflowException('The same content already exists in another dataset.', 422, 'dataset_file');
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function cleanMeta(array $meta): array
    {
        foreach (['title', 'source_reference'] as $field) {
            $meta[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) ($meta[$field] ?? '')));
            if ($meta[$field] === '') {
                throw new ChatbotDatasetWorkflowException('The title and source reference are required.', 422, $field);
            }
        }
        $meta['no_personal_data_confirmed'] = (bool) ($meta['no_personal_data_confirmed'] ?? false);
        if (isset($meta['original_filename'])) {
            $name = (string) preg_replace('/[^\p{L}\p{N} _().-]+/u', '_', basename((string) $meta['original_filename']));
            $meta['original_filename'] = mb_substr($name, 0, 255) ?: null;
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    private function versionAttributes(array $meta, array $inspection, User $admin, int $versionNo, string $language): array
    {
        $warnings = $inspection['warnings'];
        $mismatch = app(\App\Support\ChatbotDatasetFileInspector::class)->languageMismatch($inspection['content'], $language);
        if ($mismatch !== null && ! in_array($mismatch, $warnings, true)) {
            $warnings[] = $mismatch;
        }

        return [
            'version_no' => $versionNo,
            'title' => $meta['title'],
            'source_reference' => $meta['source_reference'],
            'content' => $inspection['content'],
            'content_sha256' => $inspection['sha256'],
            'size_bytes' => $inspection['size_bytes'],
            'line_count' => $inspection['line_count'],
            'original_filename' => $meta['original_filename'] ?? null,
            'screening' => ['warnings' => array_values($warnings), 'detected_type' => $inspection['detected_type']],
            'no_personal_data_confirmed' => $meta['no_personal_data_confirmed'],
            'status' => ChatbotDatasetCatalog::DRAFT,
            'created_by_user_id' => $admin->getKey(),
        ];
    }

    private function cleanNote(?string $note): ?string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", (string) $note));

        return $note === '' ? null : $note;
    }

    private function log(
        ChatbotDataset $dataset,
        ?ChatbotDatasetVersion $version,
        string $action,
        ?string $from,
        ?string $to,
        User $actor,
        ?string $note = null,
    ): void {
        ChatbotDatasetEvent::query()->create([
            'chatbot_dataset_id' => $dataset->id,
            'chatbot_dataset_version_id' => $version?->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor->getKey(),
            'actor_role' => StaffRole::label($actor->role) ?: 'Staff',
            'note' => $note,
        ]);
    }
}
