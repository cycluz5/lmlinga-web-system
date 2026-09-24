<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only record_requests table aligned with authoritative ERD shape.
 *
 * Not a migration — production ERD already owns record_requests.
 */
final class ErdRecordRequestsSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('record_requests')) {
            return;
        }

        Schema::create('record_requests', function (Blueprint $table): void {
            $table->id('request_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('household_no_submitted')->nullable();
            $table->string('zone_submitted')->nullable();
            $table->string('relationship_submitted')->nullable();
            $table->string('first_name_submitted')->nullable();
            $table->string('middle_name_submitted')->nullable();
            $table->string('last_name_submitted')->nullable();
            $table->string('mobile_number_submitted', 32)->nullable();
            $table->string('email_submitted')->nullable();
            $table->string('submitter_ip', 45)->nullable();
            $table->unsignedBigInteger('matched_resident_id')->nullable();
            $table->string('status')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }
}
