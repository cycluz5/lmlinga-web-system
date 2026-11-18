<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dataset review was removed: an Admin save now goes live at once. Versions that were still waiting
 * (draft or submitted) are promoted so no dataset text stays stuck.
 *
 * For each dataset that is not retired, its highest draft or submitted version becomes approved and the
 * previously approved version becomes superseded, with one 'published' history event. Rejected versions
 * stay as history. Writing the text to the chatbot's document folder and indexing it is not done here; run
 * `php artisan lmlinga:datasets-republish` afterwards. down() does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_datasets') || ! Schema::hasTable('chatbot_dataset_versions')) {
            return;
        }

        $note = 'Published automatically: dataset review was removed.';

        DB::transaction(function () use ($note): void {
            $datasetIds = DB::table('chatbot_datasets')->whereNull('retired_at')->orderBy('id')->pluck('id');

            foreach ($datasetIds as $datasetId) {
                $pending = DB::table('chatbot_dataset_versions')
                    ->where('chatbot_dataset_id', $datasetId)
                    ->whereIn('status', ['draft', 'submitted'])
                    ->orderByDesc('version_no')
                    ->first();
                if ($pending === null) {
                    continue;
                }

                $now = now();
                $previous = DB::table('chatbot_dataset_versions')
                    ->where('chatbot_dataset_id', $datasetId)
                    ->where('status', 'approved')
                    ->first();
                if ($previous !== null) {
                    DB::table('chatbot_dataset_versions')->where('id', $previous->id)->update(['status' => 'superseded', 'updated_at' => $now]);
                }

                DB::table('chatbot_dataset_versions')->where('id', $pending->id)->update(['status' => 'approved', 'updated_at' => $now]);
                DB::table('chatbot_dataset_events')->insert([
                    'chatbot_dataset_id' => $datasetId,
                    'chatbot_dataset_version_id' => $pending->id,
                    'action' => 'published',
                    'from_status' => $pending->status,
                    'to_status' => 'approved',
                    'actor_user_id' => null,
                    'actor_role' => 'System',
                    'note' => $note,
                    'created_at' => $now,
                ]);
                DB::table('chatbot_datasets')->where('id', $datasetId)->update(['updated_at' => $now]);
            }
        });

        Cache::forget('lmlinga.chatbot.approved-datasets');
    }

    public function down(): void
    {
        // Intentionally empty: promoted versions are not demoted again.
    }
};