<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('death_requests', function (Blueprint $table) {
            $table->id();
            $table->string('household_no', 16);
            $table->string('member_id', 16);
            $table->string('resident_name', 160);
            $table->string('resident_sex', 32)->nullable();
            $table->unsignedSmallInteger('resident_age')->nullable();
            $table->string('zone', 32)->nullable();
            $table->string('household_display_no', 32)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('cause_of_death', 500);
            $table->date('date_of_death');
            $table->string('certificate_no', 100);
            $table->string('certificate_disk', 64);
            $table->string('certificate_path', 255);
            $table->string('certificate_original_name', 160);
            $table->string('certificate_mime', 80);
            $table->unsignedInteger('certificate_size');
            $table->string('certificate_extension', 8);
            $table->string('status', 16);
            $table->string('submitted_by_name', 120);
            $table->string('submitted_by_role', 16);
            $table->timestamp('submitted_at');
            $table->string('reviewed_by_name', 120)->nullable();
            $table->string('reviewed_by_role', 16)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['household_no', 'member_id']);
            $table->index('status');
            $table->index('submitted_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX death_requests_one_pending
                 ON death_requests (household_no, member_id)
                 WHERE status = \'pending\''
            );
            DB::statement(
                'CREATE UNIQUE INDEX death_requests_one_approved
                 ON death_requests (household_no, member_id)
                 WHERE status = \'approved\''
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('death_requests');
    }
};
