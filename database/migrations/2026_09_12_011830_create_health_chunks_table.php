<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 reconciliation: health document chunk store for RagService / health:index.
 *
 * No-op if the table already exists. Does not run indexing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('health_chunks')) {
            return;
        }

        Schema::create('health_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('language', 10);
            $table->string('category', 50);
            $table->string('source_file', 255);
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->longText('embedding');
            $table->timestamps();

            $table->index('language');
            $table->index('category');
            $table->index(['language', 'category', 'source_file'], 'health_chunks_lang_cat_source_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_chunks');
    }
};
