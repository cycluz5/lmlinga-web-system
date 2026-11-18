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

/**
 * Chatbot dataset workflow. Every status change: role check, status precondition under a row lock, the change,
 * one history event, one transaction.
 *
 * Admin: upload, replace, retire, restore. An Admin save PUBLISHES the version at once: it becomes the approved
 * version and the previous one is superseded, with one 'published' history event. Writing the published text to
 * the chatbot's document folder and indexing it is done by ChatbotDatasetPublisher after the transaction, never
 * here.
 */
class ChatbotDatasetWorkflow
{
    public const ACTION_PUBLISHED = 'published';

    public const ACTION_UPLOADED = 'uploaded';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_REVISED = 'revised';

    public const ACTION_SUPERSEDED = 'superseded';

    public const ACTION_RETIRED = 'retired';

    public const ACTION_RESTORED = 'restored';

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
                'is_default' => $action === self::ACTION_IMPORTED,
                'created_by_user_id' => $admin->getKey(),
            ]);
            $version = $dataset->versions()->create($this->versionAttributes($meta, $inspection, $admin, 1, $meta['language']));
            $this->log($dataset, $version, $action, null, ChatbotDatasetCatalog::DRAFT, $admin);
            $this->publish($dataset, $version, $admin);

            return $dataset;
        });
    }

    /**
     * Change title/source reference and/or replace the file. A draft changes in place; otherwise a new version is created. Either way it is published at once.
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
            $this->assertNotDefault($dataset);
            if ($dataset->isRetired()) {
                throw new ChatbotDatasetWorkflowException('Restore this dataset before changing it.');
            }
            $open = $dataset->openVersion();

            $source = $open ?? $dataset->currentVersion();
            $inspection ??= [
                'errors' => [], 'warnings' => $source->screening['warnings'] ?? [], 'content' => $source->content,
                'sha256' => $source->content_sha256, 'size_bytes' => $source->size_bytes, 'line_count' => $source->line_count,
                'detected_type' => $source->screening['detected_type'] ?? '',
            ];
            $meta['original_filename'] ??= $source->original_filename;
            $this->assertNotDuplicate($inspection['sha256'], $dataset->id);

            $live = $dataset->approvedVersion();
            if ($live !== null && $live->content_sha256 === $inspection['sha256']
                && $live->title === $meta['title'] && $live->source_reference === $meta['source_reference']) {
                throw new ChatbotDatasetWorkflowException('Nothing changed. Edit the title, source reference or file before saving.', 422, 'title');
            }

            if ($open?->status === ChatbotDatasetCatalog::DRAFT) {
                $open->fill($this->versionAttributes($meta, $inspection, $admin, $open->version_no, $dataset->language))->save();
                $this->log($dataset, $open, self::ACTION_UPDATED, ChatbotDatasetCatalog::DRAFT, ChatbotDatasetCatalog::DRAFT, $admin);
                $this->publish($dataset, $open, $admin);

                return $open;
            }

            $version = $dataset->versions()->create($this->versionAttributes(
                $meta, $inspection, $admin, (int) $dataset->versions()->max('version_no') + 1, $dataset->language
            ));
            $this->log($dataset, $version, self::ACTION_REVISED, null, ChatbotDatasetCatalog::DRAFT, $admin);
            $this->publish($dataset, $version, $admin);

            return $version;
        });
    }

    public function retire(User $admin, ChatbotDataset $dataset): ChatbotDataset
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($admin, $dataset): ChatbotDataset {
            $dataset = $this->lock($dataset);
            $this->assertNotDefault($dataset);
            if ($dataset->isRetired()) {
                throw new ChatbotDatasetWorkflowException('This dataset is already retired.');
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
            $this->assertNotDefault($dataset);
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
            $this->publish($dataset, $draft, $admin);

            return $dataset;
        });
    }

    /**
     * Publish a saved version at once: it becomes the approved version and the previous approved version is
     * superseded. Requires the personal-data confirmation.
     */
    private function publish(ChatbotDataset $dataset, ChatbotDatasetVersion $version, User $admin): void
    {
        if (! $version->no_personal_data_confirmed) {
            throw new ChatbotDatasetWorkflowException('Confirm that the file contains no resident personal information before saving.', 422, 'no_personal_data_confirmed');
        }

        $previous = $dataset->versions()->where('status', ChatbotDatasetCatalog::APPROVED)->where('id', '!=', $version->id)->first();
        if ($previous !== null) {
            $previous->update(['status' => ChatbotDatasetCatalog::SUPERSEDED]);
            $this->log($dataset, $previous, self::ACTION_SUPERSEDED, ChatbotDatasetCatalog::APPROVED, ChatbotDatasetCatalog::SUPERSEDED, $admin);
        }
        $version->update(['status' => ChatbotDatasetCatalog::APPROVED]);
        $this->log($dataset, $version, self::ACTION_PUBLISHED, ChatbotDatasetCatalog::DRAFT, ChatbotDatasetCatalog::APPROVED, $admin, 'Published by the administrator.');
        $dataset->touch();
        DB::afterCommit(fn () => ApprovedChatbotDatasets::forget());
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

    /** Default datasets (the built-in corpus) are read-only. */
    private function assertNotDefault(ChatbotDataset $dataset): void
    {
        if ($dataset->isDefault()) {
            throw new ChatbotDatasetWorkflowException(ChatbotDataset::DEFAULT_LOCKED_MESSAGE);
        }
    }

    private function assertAdmin(User $user): void
    {
        if (! $user->isActive() || StaffRole::normalize($user->role) !== StaffRole::ADMIN) {
            throw new ChatbotDatasetWorkflowException('Only an active Admin can manage datasets.', 403);
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

    private function log(
        ChatbotDataset $dataset,
        ?ChatbotDatasetVersion $version,
        string $action,
        ?string $from,
        ?string $to,
        User $actor,
        ?string $note = null,
    ): void {
        // Default datasets keep no history.
        if ($dataset->isDefault()) {
            return;
        }

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
