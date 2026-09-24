<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot announcement Who/Where/Date/Time onto notifications for resident UI.
 * Nullable so non-announcement notification types remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'recipient_context')) {
                $table->text('recipient_context')->nullable()->after('message');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'place')) {
                $table->string('place', 120)->nullable()->after('recipient_context');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'event_date')) {
                $table->date('event_date')->nullable()->after('place');
            }
        });

        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'event_time')) {
                $table->time('event_time')->nullable()->after('event_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $columns = array_values(array_filter(
            ['recipient_context', 'place', 'event_date', 'event_time'],
            static fn (string $column): bool => Schema::hasColumn('notifications', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
