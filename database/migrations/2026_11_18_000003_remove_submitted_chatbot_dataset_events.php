<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dataset review was removed, so the old "Submitted for review" lines no longer belong in a dataset's History.
 * They were written when the existing corpus was imported while review was still on. Only 'submitted' events
 * are deleted; 'imported' and 'published' events (including the automatic-publish note) stay.
 * down() does nothing: the deleted rows cannot be rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_dataset_events')) {
            return;
        }

        DB::table('chatbot_dataset_events')->where('action', 'submitted')->delete();
    }

    public function down(): void
    {
        // Intentionally empty: deleted history rows are not restored.
    }
};
