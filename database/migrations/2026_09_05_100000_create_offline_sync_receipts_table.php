<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application-infrastructure table for durable offline-sync idempotency.
 * Not part of the authoritative health ERD. No FKs to households/residents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_sync_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 36)->unique();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->string('operation_type', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 32);
            $table->unsignedBigInteger('household_pk')->nullable();
            $table->unsignedBigInteger('resident_pk')->nullable();
            $table->string('household_no', 16)->nullable();
            $table->string('member_no', 16)->nullable();
            $table->json('result_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sync_receipts');
    }
};
