<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SR-07: chatbot health datasets uploaded by Admin and approved by a Linguist (SR-06).
 *
 * Additive only: three new tables, no existing table or row is touched. The chatbot corpus
 * (resources/health_docs, health_chunks) is NOT changed; approved datasets are not indexed here.
 * - chatbot_datasets: identity (language, category, source_name), retirement.
 * - chatbot_dataset_versions: normalised text content (stored in the database; the uploaded file is not
 *   kept), immutable once submitted, so the approved version stays current while a replacement is reviewed.
 * - chatbot_dataset_events: append-only history.
 * Staff foreign keys only on the ERD schema (user_management.user_id), like SR-05.
 * down() refuses while any dataset exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        $staffTable = Schema::hasTable('user_management') && ! Schema::hasTable('users') ? 'user_management' : null;

        if (! Schema::hasTable('chatbot_datasets')) {
            Schema::create('chatbot_datasets', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->string('language', 10);
                $table->string('category', 30);
                $table->string('source_name', 120);
                $table->timestamp('retired_at')->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['language', 'category', 'source_name']);

                if ($staffTable !== null) {
                    $table->foreign('created_by_user_id')->references('user_id')->on($staffTable)->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('chatbot_dataset_versions')) {
            Schema::create('chatbot_dataset_versions', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->foreignId('chatbot_dataset_id')->constrained('chatbot_datasets')->cascadeOnDelete();
                $table->unsignedInteger('version_no');
                $table->string('title', 160);
                $table->string('source_reference', 255);
                $table->mediumText('content');
                $table->char('content_sha256', 64)->index();
                $table->unsignedInteger('size_bytes');
                $table->unsignedInteger('line_count');
                $table->string('original_filename', 255)->nullable();
                $table->json('screening')->nullable();
                $table->boolean('no_personal_data_confirmed')->default(false);
                $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'superseded'])->default('draft')->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->unique(['chatbot_dataset_id', 'version_no']);

                if ($staffTable !== null) {
                    $table->foreign('created_by_user_id')->references('user_id')->on($staffTable)->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('chatbot_dataset_events')) {
            Schema::create('chatbot_dataset_events', function (Blueprint $table) use ($staffTable): void {
                $table->id();
                $table->foreignId('chatbot_dataset_id')->constrained('chatbot_datasets')->cascadeOnDelete();
                $table->foreignId('chatbot_dataset_version_id')->nullable()->constrained('chatbot_dataset_versions')->cascadeOnDelete();
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
        if (Schema::hasTable('chatbot_datasets') && DB::table('chatbot_datasets')->exists()) {
            throw new RuntimeException('Cannot drop the chatbot dataset tables: datasets and their review history exist.');
        }

        Schema::dropIfExists('chatbot_dataset_events');
        Schema::dropIfExists('chatbot_dataset_versions');
        Schema::dropIfExists('chatbot_datasets');
    }
};
