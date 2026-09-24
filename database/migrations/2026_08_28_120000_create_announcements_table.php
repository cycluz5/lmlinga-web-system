<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->string('message', 500);
            $table->date('event_date');
            $table->time('event_time')->nullable();
            $table->string('place', 120)->nullable();
            $table->string('target_group', 32);
            $table->json('age_presets')->nullable();
            $table->unsignedSmallInteger('age_min_months')->nullable();
            $table->unsignedSmallInteger('age_max_months')->nullable();
            $table->string('zone_mode', 16);
            $table->json('zones')->nullable();
            $table->string('audience_label', 255);
            $table->unsignedInteger('estimated_reach')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->string('posted_by_name', 120);
            $table->string('posted_by_role', 16);
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index('event_date');
            $table->index('posted_at');
            $table->index('target_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
