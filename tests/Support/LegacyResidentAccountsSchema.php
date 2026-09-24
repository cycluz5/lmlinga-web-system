<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit-only legacy resident_accounts table for Laravel test DBs.
 *
 * Not a migration — authoritative ERD uses account_id / zone_purok without resident_id.
 */
final class LegacyResidentAccountsSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('users') || Schema::hasTable('resident_accounts')) {
            return;
        }

        Schema::create('resident_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resident_id')
                ->nullable()
                ->unique()
                ->constrained('residents')
                ->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('middle_name', 100);
            $table->string('last_name', 100);
            $table->string('zone', 20);
            $table->string('email', 150)->unique();
            $table->string('password', 255);
            $table->timestamps();
        });
    }
}
