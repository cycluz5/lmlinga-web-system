<?php

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the super admin account (maria.santos) as part of `php artisan migrate`.
 * Skipped when the account already exists, so an existing password is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (User::query()->where('username', 'maria.santos')->exists()) {
            return;
        }

        (new SuperAdminSeeder)->run();
    }

    public function down(): void
    {
        // Intentionally left blank — rolling back must not delete the admin account.
    }
};
