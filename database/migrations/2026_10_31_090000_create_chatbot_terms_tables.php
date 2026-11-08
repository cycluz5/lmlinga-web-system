<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SR-05: Bikol-Iriga terms managed by Admin and verified by a Linguist (SR-06).
 *
 * Additive only: three new tables, no existing table or row is touched.
 * - chatbot_terms: one row per term (identity, retirement).
 * - chatbot_term_versions: the term's content; reviewed versions are never edited or deleted, so an
 *   approved version stays in use while an edited replacement waits for review (D-T1 versioning).
 * - chatbot_term_events: append-only history (who, role at the time, action, note, when).
 * Staff foreign keys are added only on the ERD schema (user_management.user_id), like announcements.
 * down() refuses while any term exists so reviewed history is never dropped by a rollback.
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
        if (Schema::hasTable('chatbot_terms') && DB::table('chatbot_terms')->exists()) {
            throw new RuntimeException('Cannot drop the chatbot term tables: terms and their review history exist.');
        }

        Schema::dropIfExists('chatbot_term_events');
        Schema::dropIfExists('chatbot_term_versions');
        Schema::dropIfExists('chatbot_terms');
    }
};
