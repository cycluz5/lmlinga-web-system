<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The datasets imported from the existing corpus become the "default" datasets: flagged, read-only,
 * and without history. Their imported/published/superseded events are deleted.
 * down() only drops the column: the deleted history rows cannot be rebuilt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_datasets')) {
            return;
        }

        if (! Schema::hasColumn('chatbot_datasets', 'is_default')) {
            Schema::table('chatbot_datasets', function (Blueprint $table): void {
                $table->boolean('is_default')->default(false)->index();
            });
        }

        if (! Schema::hasTable('chatbot_dataset_events')) {
            return;
        }

        $ids = DB::table('chatbot_dataset_events')->where('action', 'imported')->distinct()->pluck('chatbot_dataset_id');
        foreach ($ids->chunk(500) as $chunk) {
            DB::table('chatbot_datasets')->whereIn('id', $chunk)->update(['is_default' => true]);
            DB::table('chatbot_dataset_events')->whereIn('chatbot_dataset_id', $chunk)->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_datasets', 'is_default')) {
            Schema::table('chatbot_datasets', function (Blueprint $table): void {
                $table->dropColumn('is_default');
            });
        }
    }
};
