<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('household_no', 16);
            $table->string('member_id', 16);
            $table->string('status', 16);
            $table->foreignId('death_request_id')->nullable()->constrained('death_requests')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['household_no', 'member_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_statuses');
    }
};
