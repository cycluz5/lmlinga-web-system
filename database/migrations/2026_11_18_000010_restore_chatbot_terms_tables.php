<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the Bikol-Iriga term tables on databases where main's "drop_chatbot_terms_tables" migration already
 * ran before Language Management was brought back. Creates only what is missing, so it does nothing on a
 * database that still has the tables. Same schema as 2026_10_31_090000_create_chatbot_terms_tables.
 * down() is a no-op: the original create migration owns the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $staffTable = Schema::hasTable('user_management') && ! Schema::hasTable('users') ? 'user_management' : null;

        if (! Schema::hasTable('chatbot_terms')) {
            Schema::create('chatbot_terms', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->string('language', 10)->default('bcl')->index();
                $table->timestamp('retired_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                if ($staffTable !== null) {
                    $table->foreign('created_by_user_id')->references('user_id')->on($staffTable)->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('chatbot_term_versions')) {
            Schema::create('chatbot_term_versions', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->foreignId('chatbot_term_id')->constrained('chatbot_terms')->cascadeOnDelete();
                $table->unsignedInteger('version_no');
                $table->string('term', 120);
                $table->string('english_meaning', 255);
                $table->string('tagalog_meaning', 255)->nullable();
                $table->string('part_of_speech', 30)->nullable();
                $table->text('example_usage')->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'superseded'])->default('draft')->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['chatbot_term_id', 'version_no']);
                $table->index('term');

                if ($staffTable !== null) {
                    $table->foreign('created_by_user_id')->references('user_id')->on($staffTable)->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('chatbot_term_events')) {
            Schema::create('chatbot_term_events', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->foreignId('chatbot_term_id')->constrained('chatbot_terms')->cascadeOnDelete();
                $table->foreignId('chatbot_term_version_id')->nullable()->constrained('chatbot_term_versions')->cascadeOnDelete();
                $table->string('action', 16);
                $table->string('from_status', 16)->nullable();
                $table->string('to_status', 16)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('actor_role', 16);
                $table->text('note')->nullable();
                $table->timestamp('created_at')->nullable();

                if ($staffTable !== null) {
                    $table->foreign('actor_user_id')->references('user_id')->on($staffTable)->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty.
    }
};
