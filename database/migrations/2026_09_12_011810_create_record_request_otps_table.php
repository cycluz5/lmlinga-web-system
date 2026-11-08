<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 reconciliation: request-scoped OTP storage for household verification.
 *
 * Matches App\Models\RecordRequestOtp and OTP issuer/verifier services.
 * No-op if the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_request_otps')) {
            return;
        }

        if (! Schema::hasTable('record_requests') || ! Schema::hasColumn('record_requests', 'request_id')) {
            return;
        }

        Schema::create('record_request_otps', function (Blueprint $table) {
            $table->id('otp_id');
            $table->unsignedBigInteger('request_id');
            $table->string('code_hash', 255);
            $table->string('destination_fingerprint', 255);
            // DATETIME so MySQL will not attach ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('expires_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->foreign('request_id', 'fk_record_request_otps_request_id')
                ->references('request_id')
                ->on('record_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_request_otps');
    }
};
