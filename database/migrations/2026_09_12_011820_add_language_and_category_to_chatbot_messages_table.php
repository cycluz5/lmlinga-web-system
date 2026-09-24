<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 reconciliation: add RAG language/category columns to existing chat history.
 *
 * chatbot_messages already exists — ALTER only. Existing rows/history are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_messages')) {
            return;
        }

        if (! Schema::hasColumn('chatbot_messages', 'language')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->string('language', 10)->default('bcl')->after('message_text');
            });
        }

        if (! Schema::hasColumn('chatbot_messages', 'category')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->string('category', 50)->nullable()->after('language');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chatbot_messages')) {
            return;
        }

        if (Schema::hasColumn('chatbot_messages', 'category')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }

        if (Schema::hasColumn('chatbot_messages', 'language')) {
            Schema::table('chatbot_messages', function (Blueprint $table) {
                $table->dropColumn('language');
            });
        }
    }
};
