<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class ChatbotMainNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private bool $erdReady = false;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

    public function test_verified_resident_sees_own_notifications_newest_first_with_unread_state(): void
    {
        [$accountId] = $this->seedVerifiedResident('own.notif@example.test', '000701');
        $this->ensureNotificationsTable();

        DB::table('notifications')->insert([
            [
                'account_id' => $accountId,
                'notification_type' => 'System',
                'title' => 'Older Notice',
                'message' => 'Older body',
                'related_request_id' => null,
                'related_conversation_id' => null,
                'is_read' => 1,
                'created_at' => '2026-09-01 08:00:00',
            ],
            [
                'account_id' => $accountId,
                'notification_type' => 'System',
                'title' => 'Newest Notice',
                'message' => 'Newest body',
                'related_request_id' => null,
                'related_conversation_id' => null,
                'is_read' => 0,
                'created_at' => '2026-09-10 12:00:00',
            ],
        ]);

        $html = $this->actingAsResidentSession($accountId, 'own.notif@example.test')
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Newest Notice', $html);
        $this->assertStringContainsString('Older Notice', $html);
        $this->assertStringContainsString('data-notification-read="false"', $html);
        $this->assertStringContainsString('data-notification-read="true"', $html);
        $this->assertStringContainsString('data-notification-title="Newest Notice"', $html);
        $this->assertStringContainsString('data-notification-type="System"', $html);
        $this->assertStringContainsString('data-notification-kind="generic"', $html);
        $this->assertStringContainsString('data-notification-who=""', $html);
        $this->assertStringContainsString('data-lml-notification-modal-detail="who"', $html);
        $this->assertStringContainsString('data-lml-notification-modal-detail="where"', $html);
        $this->assertStringNotContainsString('data-lml-notification-modal-detail="type"', $html);
        $this->assertStringContainsString('data-lml-notification-modal', $html);
        $this->assertStringContainsString('data-lml-notification-modal-generic-details', $html);
        $this->assertStringContainsString('aria-label="1 unread"', $html);
        $this->assertTrue(
            strpos($html, 'Newest Notice') < strpos($html, 'Older Notice'),
            'Newest notification should appear before older in the list markup.'
        );
        $this->assertStringNotContainsString('Child Immunization', $html);
        $this->assertStringNotContainsString('Jane Doe', $html);
    }

    public function test_announcement_context_fields_are_exposed_on_notification_rows(): void
    {
        [$accountId] = $this->seedVerifiedResident('context.notif@example.test', '000711');
        $this->ensureNotificationsTable();

        DB::table('notifications')->insert([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => 'Infant Checkup',
            'message' => 'Bring vaccination card.',
            'recipient_context' => 'Ben C Child',
            'place' => 'Barangay Health Center',
            'event_date' => '2026-10-01',
            'event_time' => '09:30:00',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => '2026-09-01 08:00:00',
        ]);

        $html = $this->actingAsResidentSession($accountId, 'context.notif@example.test')
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-notification-who="Ben C Child"', $html);
        $this->assertStringContainsString('data-notification-place="Barangay Health Center"', $html);
        $this->assertStringContainsString('data-notification-date="October 1, 2026"', $html);
        $this->assertStringContainsString('data-notification-time="9:30 AM"', $html);
        $this->assertStringContainsString('data-notification-message="Bring vaccination card."', $html);
        $this->assertStringNotContainsString('data-notification-date="September 1, 2026"', $html);
        $this->assertStringContainsString('>Who</dt>', $html);
        $this->assertStringContainsString('>Where</dt>', $html);
        $this->assertStringNotContainsString('>Type</dt>', $html);
    }

    public function test_verified_resident_does_not_see_another_accounts_notifications(): void
    {
        $this->ensureErdSchema();
        [$ownAccountId] = $this->seedVerifiedResident('viewer@example.test', '000702');
        [$otherAccountId] = $this->seedVerifiedResident('other@example.test', '000703');
        $this->ensureNotificationsTable();

        DB::table('notifications')->insert([
            'account_id' => $otherAccountId,
            'notification_type' => 'System',
            'title' => 'Private Other Notice',
            'message' => 'Should not leak',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $html = $this->actingAsResidentSession($ownAccountId, 'viewer@example.test')
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Private Other Notice', $html);
        $this->assertStringNotContainsString('Should not leak', $html);
        $this->assertStringNotContainsString('data-lml-notifications', $html);
    }

    public function test_unverified_resident_gets_empty_notification_list(): void
    {
        $this->ensureErdSchema();
        $this->ensureNotificationsTable();

        $accountId = DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Unverified',
            'middle_name' => 'N',
            'last_name' => 'Resident',
            'zone_purok' => '1',
            'email' => 'unverified.notif@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('notifications')->insert([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => 'Hidden Until Verified',
            'message' => 'Must not show',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $html = $this->actingAsResidentSession($accountId, 'unverified.notif@example.test')
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Hidden Until Verified', $html);
        $this->assertStringNotContainsString('data-lml-notifications', $html);
        $this->assertStringNotContainsString('Jane Doe', $html);
    }

    public function test_resident_can_mark_own_notification_as_read(): void
    {
        [$accountId] = $this->seedVerifiedResident('mark.own@example.test', '000710');
        $this->ensureNotificationsTable();

        $notificationId = DB::table('notifications')->insertGetId([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => 'Mark Me Read',
            'message' => 'Unread body',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $this->actingAsResidentSession($accountId, 'mark.own@example.test')
            ->postJson(route('chatbot.notifications.read', ['notificationId' => $notificationId]))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'notification_id' => $notificationId,
                'is_read' => true,
            ]);

        $this->assertSame(1, (int) DB::table('notifications')->where('notification_id', $notificationId)->value('is_read'));
    }

    public function test_resident_cannot_mark_another_accounts_notification(): void
    {
        $this->ensureErdSchema();
        [$ownAccountId] = $this->seedVerifiedResident('mark.viewer@example.test', '000711');
        [$otherAccountId] = $this->seedVerifiedResident('mark.other@example.test', '000712');
        $this->ensureNotificationsTable();

        $notificationId = DB::table('notifications')->insertGetId([
            'account_id' => $otherAccountId,
            'notification_type' => 'System',
            'title' => 'Other Account Notice',
            'message' => 'Private',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $this->actingAsResidentSession($ownAccountId, 'mark.viewer@example.test')
            ->postJson(route('chatbot.notifications.read', ['notificationId' => $notificationId]))
            ->assertNotFound();

        $this->assertSame(0, (int) DB::table('notifications')->where('notification_id', $notificationId)->value('is_read'));
    }

    public function test_marking_already_read_notification_is_idempotent(): void
    {
        [$accountId] = $this->seedVerifiedResident('mark.again@example.test', '000713');
        $this->ensureNotificationsTable();

        $notificationId = DB::table('notifications')->insertGetId([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => 'Already Read',
            'message' => 'Was read',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 1,
            'created_at' => now(),
        ]);

        $this->actingAsResidentSession($accountId, 'mark.again@example.test')
            ->postJson(route('chatbot.notifications.read', ['notificationId' => $notificationId]))
            ->assertOk()
            ->assertJson(['ok' => true, 'is_read' => true]);

        $this->assertSame(1, (int) DB::table('notifications')->where('notification_id', $notificationId)->value('is_read'));
    }

    public function test_unauthenticated_cannot_mark_notification_read(): void
    {
        $this->ensureErdSchema();
        $this->ensureNotificationsTable();

        $accountId = DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Guest',
            'middle_name' => 'T',
            'last_name' => 'Target',
            'zone_purok' => '1',
            'email' => 'guest.mark@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notificationId = DB::table('notifications')->insertGetId([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => 'Guest Blocked',
            'message' => 'No session',
            'related_request_id' => null,
            'related_conversation_id' => null,
            'is_read' => 0,
            'created_at' => now(),
        ]);

        $this->postJson(route('chatbot.notifications.read', ['notificationId' => $notificationId]))
            ->assertRedirect(route('chatbot.login'));

        $this->assertSame(0, (int) DB::table('notifications')->where('notification_id', $notificationId)->value('is_read'));
    }

    /**
     * @return array{0: int|string}
     */
    private function seedVerifiedResident(string $email, string $householdNo): array
    {
        $this->ensureErdSchema();

        $householdId = DB::table('households')->insertGetId([
            'household_no' => $householdNo,
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Verified',
            'middle_name' => 'N',
            'last_name' => 'Resident',
            'birthday' => '1990-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('resident_accounts')->insertGetId([
            'resident_id' => $residentId,
            'first_name' => 'Verified',
            'middle_name' => 'N',
            'last_name' => 'Resident',
            'zone_purok' => '1',
            'email' => $email,
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestId = DB::table('record_requests')->insertGetId([
            'account_id' => $accountId,
            'household_no_submitted' => $householdNo,
            'zone_submitted' => '1',
            'relationship_submitted' => 'Self',
            'first_name_submitted' => 'Verified',
            'middle_name_submitted' => 'N',
            'last_name_submitted' => 'Resident',
            'mobile_number_submitted' => '09170000000',
            'email_submitted' => $email,
            'matched_resident_id' => $residentId,
            'status' => RecordRequest::STATUS_APPROVED,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('record_request_otps')->insert([
            'request_id' => $requestId,
            'channel' => 'sms',
            'code_hash' => bcrypt('123456'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$accountId];
    }

    private function ensureErdSchema(): void
    {
        if ($this->erdReady) {
            return;
        }

        ClientTestingErdSchema::ensure();

        if (! Schema::hasColumn('resident_accounts', 'resident_id')) {
            Schema::table('resident_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('resident_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('record_request_otps')) {
            Schema::create('record_request_otps', function (Blueprint $table): void {
                $table->id('otp_id');
                $table->unsignedBigInteger('request_id');
                $table->string('channel', 20)->nullable();
                $table->string('code_hash');
                $table->string('destination_fingerprint')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamp('invalidated_at')->nullable();
                $table->unsignedInteger('attempt_count')->default(0);
                $table->unsignedInteger('resend_count')->default(0);
                $table->timestamps();
            });
        }

        $this->erdReady = true;
    }

    private function actingAsResidentSession(int|string $accountId, string $email): static
    {
        return $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => $email,
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);
    }

    private function ensureNotificationsTable(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->id('notification_id');
                $table->unsignedBigInteger('account_id');
                $table->string('notification_type', 64);
                $table->string('title', 150);
                $table->text('message')->nullable();
                $table->text('recipient_context')->nullable();
                $table->string('place', 120)->nullable();
                $table->date('event_date')->nullable();
                $table->time('event_time')->nullable();
                $table->unsignedBigInteger('related_request_id')->nullable();
                $table->unsignedBigInteger('related_conversation_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->useCurrent();
            });

            return;
        }

        if (! Schema::hasColumn('notifications', 'recipient_context')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->text('recipient_context')->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'place')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->string('place', 120)->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'event_date')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->date('event_date')->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'event_time')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->time('event_time')->nullable();
            });
        }
    }
}
