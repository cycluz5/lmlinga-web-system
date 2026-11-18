<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A version made by the AI translator is a draft until the Admin verifies it. The chatbot only reads approved
 * versions, so an unverified machine translation is never used; this flag marks such a draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chatbot_dataset_versions') && ! Schema::hasColumn('chatbot_dataset_versions', 'machine_translated')) {
            Schema::table('chatbot_dataset_versions', function (Blueprint $table): void {
                $table->boolean('machine_translated')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chatbot_dataset_versions', 'machine_translated')) {
            Schema::table('chatbot_dataset_versions', function (Blueprint $table): void {
                $table->dropColumn('machine_translated');
            });
        }
    }
};
