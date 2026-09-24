<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\ResidentAuthenticator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\TestCase;

class ChatbotRegisterAndMainHouseholdUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

    public function test_register_page_includes_single_submit_guard(): void
    {
        $html = $this->get(route('chatbot.register'))->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-register-form', $html);
        $this->assertStringContainsString('data-lml-register-submit', $html);
        $this->assertStringContainsString('Creating account...', $html);
        $this->assertStringContainsString('submitButton.disabled = true', $html);
        $this->assertStringContainsString('form.checkValidity', $html);
        $this->assertStringNotContainsString('Submission is prevented until backend registration is wired', $html);
        $this->assertStringContainsString('action="'.e(route('chatbot.register.store')).'"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }

    public function test_successful_registration_creates_one_account_and_redirects_to_login(): void
    {
        ClientTestingErdSchema::ensure();

        $response = $this->post(route('chatbot.register.store'), [
            'first_name' => 'Ana',
            'middle_name' => 'B',
            'last_name' => 'Cruz',
            'zone' => '2',
            'email' => 'new.resident@example.test',
            'password' => 'SafePassw0rd!x',
        ]);

        $response->assertRedirect(route('chatbot.login'));
        $response->assertSessionHas('success', 'Account created successfully. You can now log in.');

        $this->assertSame(1, ResidentAccount::query()->where('email', 'new.resident@example.test')->count());
    }

    public function test_duplicate_email_registration_still_fails_unique_validation(): void
    {
        ClientTestingErdSchema::ensure();

        DB::table('resident_accounts')->insert([
            'first_name' => 'Existing',
            'middle_name' => 'A',
            'last_name' => 'User',
            'zone_purok' => '1',
            'email' => 'taken@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->from(route('chatbot.register'))
            ->post(route('chatbot.register.store'), [
                'first_name' => 'Ana',
                'middle_name' => 'B',
                'last_name' => 'Cruz',
                'zone' => '2',
                'email' => 'taken@example.test',
                'password' => 'SafePassw0rd!x',
            ])
            ->assertRedirect(route('chatbot.register'))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, ResidentAccount::query()->where('email', 'taken@example.test')->count());
    }

    public function test_chatbot_main_hides_household_profile_line_when_unverified(): void
    {
        ClientTestingErdSchema::ensure();

        $accountId = DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Unverified',
            'middle_name' => 'X',
            'last_name' => 'Resident',
            'zone_purok' => '3',
            'email' => 'unverified.main@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'unverified.main@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Unverified X Resident', $html);
        $this->assertStringNotContainsString('class="lml-chatbot-main__household"', $html);
        $this->assertStringNotContainsString('bi-house-door', $html);
        $this->assertStringContainsString('Request Household Record', $html);
    }

    public function test_chatbot_main_hides_household_number_when_resident_id_set_but_access_not_granted(): void
    {
        ClientTestingErdSchema::ensure();

        if (! Schema::hasColumn('resident_accounts', 'resident_id')) {
            Schema::table('resident_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('resident_id')->nullable()->unique();
            });
        }

        $householdId = DB::table('households')->insertGetId([
            'household_no' => '000123',
            'purok' => 'Zone 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Linked',
            'middle_name' => 'Only',
            'last_name' => 'Resident',
            'birthday' => '1990-01-01',
            'sex' => 'Female',
            'civil_status' => 'Single',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accountId = DB::table('resident_accounts')->insertGetId([
            'resident_id' => $residentId,
            'first_name' => 'Stale',
            'middle_name' => 'Link',
            'last_name' => 'Account',
            'zone_purok' => '1',
            'email' => 'stale.link@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('record_requests')->insert([
            'account_id' => $accountId,
            'household_no_submitted' => '999999',
            'zone_submitted' => '1',
            'relationship_submitted' => 'Self',
            'first_name_submitted' => 'Stale',
            'middle_name_submitted' => 'Link',
            'last_name_submitted' => 'Account',
            'mobile_number_submitted' => '09171234567',
            'email_submitted' => 'stale.link@example.test',
            'matched_resident_id' => $residentId,
            'status' => RecordRequest::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'stale.link@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="lml-chatbot-main__household"', $html);
        $this->assertStringNotContainsString('bi-house-door', $html);
        $this->assertStringNotContainsString('HH 000123', $html);
        $this->assertStringNotContainsString('999999', $html);
        $this->assertStringContainsString('Request Sent', $html);
    }

    public function test_chatbot_main_shows_official_household_number_when_verified_access_granted(): void
    {
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

        $householdId = DB::table('households')->insertGetId([
            'household_no' => '000456',
            'purok' => 'Zone 2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $residentId = DB::table('residents')->insertGetId([
            'household_id' => $householdId,
            'first_name' => 'Verified',
            'middle_name' => 'Y',
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
            'middle_name' => 'Y',
            'last_name' => 'Resident',
            'zone_purok' => '2',
            'email' => 'verified.main@example.test',
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $requestId = DB::table('record_requests')->insertGetId([
            'account_id' => $accountId,
            'household_no_submitted' => '111111',
            'zone_submitted' => '2',
            'relationship_submitted' => 'Self',
            'first_name_submitted' => 'Verified',
            'middle_name_submitted' => 'Y',
            'last_name_submitted' => 'Resident',
            'mobile_number_submitted' => '09170001111',
            'email_submitted' => 'verified.main@example.test',
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

        $html = $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'verified.main@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ])->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('class="lml-chatbot-main__household"', $html);
        $this->assertStringContainsString('bi-house-door', $html);
        $this->assertStringContainsString('HH 000456', $html);
        $this->assertStringNotContainsString('111111', $html);
        $this->assertStringContainsString('Access Household Record', $html);
    }
}
