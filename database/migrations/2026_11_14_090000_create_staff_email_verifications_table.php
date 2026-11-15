<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 1 — health-worker email ownership verification.
 *
 * Additive only: a new table, no change to users / user_management / worker_appointments and no backfill.
 * Existing staff accounts have no row and therefore keep working exactly as before. No foreign key is
 * declared because the staff table is either `users` (legacy) or `user_management` (ERD mode).
 * Idempotent for partially prepared schemas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_email_verifications')) {
            return;
        }

        Schema::create('staff_email_verifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('email', 255);
            $table->string('code_hash', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sends_in_window')->default(0);
            $table->timestamp('send_window_started_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index('verified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_email_verifications');
    }
};
