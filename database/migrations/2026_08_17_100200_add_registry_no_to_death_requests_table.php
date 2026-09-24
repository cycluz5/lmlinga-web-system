<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('death_requests', 'registry_no')) {
            return;
        }

        Schema::table('death_requests', function (Blueprint $table) {
            $table->string('registry_no', 100)->default('')->after('date_of_death');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('death_requests', 'registry_no')) {
            return;
        }

        Schema::table('death_requests', function (Blueprint $table) {
            $table->dropColumn('registry_no');
        });
    }
};
