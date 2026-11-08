<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resident chatbot portal tables (ERD shape from Final_DB_9-24-26.sql).
 *
 * These tables previously existed only in the imported ERD dump, so a database
 * built from `php artisan migrate` alone had no resident_accounts and chatbot
 * registration failed with a 500. Each table is created only when missing, so
 * databases imported from the dump are left untouched.
 *
 * The 2026_09_12 follow-up migrations were no-ops on such databases (their
 * parent tables did not exist yet); they are idempotent and re-run here.
 */
return new class extends Migration
{
    private const FOLLOW_UP_MIGRATIONS = [
        '2026_09_12_011800_add_resident_id_to_resident_accounts_table.php',
        '2026_09_12_011810_create_record_request_otps_table.php',
        '2026_09_12_011820_add_language_and_category_to_chatbot_messages_table.php',
        '2026_09_12_191500_fix_record_request_otp_expires_at_no_auto_update.php',
        '2026_09_12_220000_add_announcement_context_to_notifications_table.php',
    ];

    public function up(): void
    {
        // PHPUnit builds these tables per test in the shape each test needs
        // (tests/Support/*Schema.php), which skip when a table already exists.
        if (app()->runningUnitTests()) {
            return;
        }

        if (! Schema::hasTable('resident_accounts')) {
            Schema::create('resident_accounts', function (Blueprint $table) {
                $table->id('account_id');
                $table->string('first_name', 100);
                $table->string('middle_name', 100)->nullable();
                $table->string('last_name', 100);
                $table->string('zone_purok', 20)->nullable();
                $table->string('email', 150)->unique('uq_residentacct_email');
                $table->string('password', 255);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('chatbot_conversations')) {
            Schema::create('chatbot_conversations', function (Blueprint $table) {
                $table->id('conversation_id');
                $table->unsignedBigInteger('account_id');
                $table->string('title', 150)->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->foreign('account_id', 'fk_chatconv_account')
                    ->references('account_id')
                    ->on('resident_accounts');
            });
        }

        if (! Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $table) {
                $table->id('message_id');
                $table->unsignedBigInteger('conversation_id');
                $table->enum('sender', ['Resident', 'Chatbot']);
                $table->text('message_text');
                $table->timestamp('sent_at')->useCurrent();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('conversation_id', 'fk_chatmsg_conv')
                    ->references('conversation_id')
                    ->on('chatbot_conversations');
            });
        }

        if (! Schema::hasTable('record_requests')) {
            // ERD dump keys residents by resident_id; migration-built databases use id.
            $residentKey = Schema::hasColumn('residents', 'resident_id') ? 'resident_id' : 'id';

            Schema::create('record_requests', function (Blueprint $table) use ($residentKey) {
                $table->id('request_id');
                $table->unsignedBigInteger('account_id');
                $table->string('household_no_submitted', 50);
                $table->string('zone_submitted', 20);
                $table->string('relationship_submitted', 50);
                $table->string('first_name_submitted', 100);
                $table->string('middle_name_submitted', 100);
                $table->string('last_name_submitted', 100);
                $table->string('mobile_number_submitted', 20);
                $table->string('email_submitted', 150);
                $table->string('submitter_ip', 45)->nullable();
                $table->unsignedBigInteger('matched_resident_id')->nullable();
                $table->enum('status', ['Pending', 'No Match', 'Awaiting OTP', 'Approved', 'Denied'])->default('Pending');
                $table->text('decision_reason')->nullable();
                $table->timestamp('evaluated_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

                $table->foreign('account_id', 'fk_recreq_account')
                    ->references('account_id')
                    ->on('resident_accounts');
                $table->foreign('matched_resident_id', 'fk_recreq_resident')
                    ->references($residentKey)
                    ->on('residents');
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id('notification_id');
                $table->unsignedBigInteger('account_id');
                $table->enum('notification_type', ['Record Request Update', 'Chatbot Message', 'System']);
                $table->string('title', 150);
                $table->text('message')->nullable();
                $table->unsignedBigInteger('related_request_id')->nullable();
                $table->unsignedBigInteger('related_conversation_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('account_id', 'fk_notif_account')
                    ->references('account_id')
                    ->on('resident_accounts');
                $table->foreign('related_conversation_id', 'fk_notif_conv')
                    ->references('conversation_id')
                    ->on('chatbot_conversations');
                $table->foreign('related_request_id', 'fk_notif_request')
                    ->references('request_id')
                    ->on('record_requests');
            });
        }

        if (! Schema::hasTable('resident_password_resets')) {
            Schema::create('resident_password_resets', function (Blueprint $table) {
                $table->id('reset_id');
                $table->unsignedBigInteger('account_id');
                $table->string('reset_token', 255);
                $table->timestamp('requested_at')->useCurrent();
                // DATETIME so MySQL will not attach ON UPDATE CURRENT_TIMESTAMP.
                $table->dateTime('expires_at');
                $table->boolean('is_used')->default(false);
                $table->timestamp('used_at')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('account_id', 'fk_residentreset_account')
                    ->references('account_id')
                    ->on('resident_accounts');
            });
        }

        foreach (self::FOLLOW_UP_MIGRATIONS as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    public function down(): void
    {
        // Intentionally left blank — these tables hold resident portal data and
        // may predate this migration (ERD dump); rolling back must not drop them.
    }
};
