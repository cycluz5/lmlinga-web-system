<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending resident self-registrations. The ResidentAccount is created only after the emailed
 * code is entered correctly, so a wrong or fake email never takes up an address.
 * Additive and idempotent; no foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_registration_verifications')) {
            return;
        }

        Schema::create('resident_registration_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150)->unique();
            $table->text('payload');
            $table->string('code_hash', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sends_in_window')->default(0);
            $table->timestamp('send_window_started_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_registration_verifications');
    }
};
