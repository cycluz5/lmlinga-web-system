<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Support\RecordRequestErdMode;
use App\Support\ResidentAuthenticator;
use App\Support\StaffRole;
use App\Support\UiRole;
use App\Support\UserManagementErdMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientTestingErdSchema;
use Tests\Support\ErdRecordRequestsSchema;
use Tests\TestCase;

class HouseholdRequestVerificationUiTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Support\HouseholdLinkingFixtures;

    /**
     * chatbot.household.* routes sit behind resident.chatbot; the controllers read the ERD
     * resident_accounts.account_id key, so build the ERD schema and sign in a resident.
     */
    private function actingAsErdResident(): int
    {
        ClientTestingErdSchema::ensure();
        RecordRequestErdMode::resetCachedState();
        UserManagementErdMode::resetCachedState();

        $accountId = (int) DB::table('resident_accounts')->insertGetId([
            'first_name' => 'Verify',
            'middle_name' => '',
            'last_name' => 'Resident',
            'zone_purok' => '1',
            'email' => 'verify.resident@example.test',
            // Request-form UI tests model a resident who completed email verification (SR-03).
            'email_verified_at' => now(),
            'password' => bcrypt('SafePassw0rd!x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession([
            ResidentAuthenticator::SESSION_ACCOUNT_ID => $accountId,
            ResidentAuthenticator::SESSION_EMAIL => 'verify.resident@example.test',
            ResidentAuthenticator::SESSION_LOGIN_ESTABLISHED => true,
        ]);

        return $accountId;
    }

    private function seedRequest(int $accountId, string $status): void
    {
        DB::table('record_requests')->insert([
            'account_id' => $accountId,
            'household_no_submitted' => '121',
            'zone_submitted' => '1',
            'relationship_submitted' => 'Head',
            'first_name_submitted' => 'Verify',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'Resident',
            'mobile_number_submitted' => '09171234567',
            'email_submitted' => 'verify.resident@example.test',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_resident_request_form_describes_automatic_verification(): void
    {
        // Original automatic-matching copy is the legacy linking mode (emergency rollback).
        config(['lmlinga.household_linking.mode' => 'legacy']);
        $this->actingAsErdResident();
        $html = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('automatically compare', $html);
        $this->assertStringContainsString('Matching is automatic', $html);
        $this->assertStringContainsString('not a manual Admin approval step', $html);
        $this->assertStringContainsString('Submit Request', $html);
        $this->assertStringContainsString('action="'.e(route('chatbot.household.verification.store')).'"', $html);
        $this->assertStringNotContainsString('reviewed before your identity', $html);
    }

    public function test_resident_request_form_describes_staff_identity_verification(): void
    {
        config(['lmlinga.household_linking.mode' => 'staff_verified']);
        $this->actingAsErdResident();
        $html = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('Barangay staff then verify your identity in person', $html);
        $this->assertStringContainsString('valid ID', $html);
        $this->assertStringNotContainsString('Matching is automatic', $html);
        $this->assertStringNotContainsString('not a manual Admin approval step', $html);
        $this->assertStringContainsString('Submit Request', $html);
        $this->assertStringContainsString('action="'.e(route('chatbot.household.verification.store')).'"', $html);
    }

    public function test_daily_limit_state_hides_submit_action(): void
    {
        $this->actingAsErdResident();

        $html = $this->get(route('chatbot.household.verification', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Daily request limit reached', $html);
        $this->assertStringContainsString('maximum number of household record requests allowed today', $html);
        $this->assertStringNotContainsString('data-lml-household-request-form', $html);
        $this->assertStringNotContainsString('>Submit Request</', $html);
    }

    public function test_status_page_requires_a_signed_in_resident(): void
    {
        $this->get(route('chatbot.household.verification.status'))->assertRedirect();
    }

    public function test_status_page_renders_automatic_verification_states(): void
    {
        // The automatic wording belongs to the legacy linking mode (emergency rollback).
        config(['lmlinga.household_linking.mode' => 'legacy']);
        $this->ensureLinkingSchema();
        $accountId = $this->actingAsErdResident();

        $states = [
            'Pending' => ['Verification in progress', 'compared with barangay records'],
            'Approved' => ['Match found', 'matched a household record'],
            'No Match' => ['No match found', 'could not find a matching household record'],
        ];

        foreach ($states as $status => [$label, $detail]) {
            DB::table('record_requests')->where('account_id', $accountId)->delete();
            $this->seedRequest($accountId, $status);

            $html = $this->get(route('chatbot.household.verification.status'))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString($detail, $html);
            $this->assertStringContainsString('automatic verification', strtolower($html));
            $this->assertStringNotContainsString('>Skip</', $html);
            $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Skip\s*<\/button>/i', $html);
            $this->assertStringNotContainsString('Approve', $html);
            $this->assertStringNotContainsString('Reject request', $html);
        }
    }

    public function test_admin_household_requests_remain_monitoring_without_manual_approve(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Household Requests', $html);
        $this->assertStringContainsString('Monitor automatic household record verification history and results.', $html);
        $this->assertStringContainsString('>View</', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('Reject request', $html);
        $this->assertStringNotContainsString('Manual Review', $html);
        $this->assertStringNotContainsString('>Verify</', $html);
        $this->assertStringNotContainsString('enforced by the backend later', $html);
    }

    public function test_admin_household_request_details_use_automatic_verification_semantics(): void
    {
        ErdRecordRequestsSchema::ensure();

        $record = \App\Models\RecordRequest::factory()->create([
            'first_name_submitted' => 'Test',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'Requester',
            'status' => 'Approved',
        ]);

        $publicId = \App\Support\HouseholdRecordRequestPresenter::publicId((int) $record->getKey());

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('household-requests.view', ['id' => $publicId]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Automatic verification result for this household record access request.', $html);
        $this->assertStringContainsString('Verification result for', $html);
        $this->assertStringContainsString('Test Requester', $html);
        $this->assertStringContainsString('Automatic verification result', $html);
        $this->assertStringContainsString('Verification Result', $html);
        $this->assertStringContainsString('Back to Household Requests', $html);
        $this->assertStringContainsString('Exit', $html);
        $this->assertStringNotContainsString('Review result for', $html);
        $this->assertStringNotContainsString('>Decision</', $html);
        $this->assertStringNotContainsString('Manual Review', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('Reject request', $html);
        $this->assertStringNotContainsString('>Approve</', $html);
        $this->assertStringNotContainsString('>Reject</', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Approve\s*<\/button>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Reject\s*<\/button>/i', $html);
    }

    public function test_jaica_demo_fixture_age_matches_child_birthday_context(): void
    {
        // The demo fixture is gone; household information is served only for an approved,
        // OTP-verified request, so seed that household with the same infant and measurement.
        Carbon::setTestNow('2026-08-20 09:00:00');
        $accountId = $this->actingAsErdResident();

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
            'household_no' => 'HH-905', 'purok' => 'Zone 1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $headId = DB::table('residents')->insertGetId([
            'household_id' => $householdId, 'first_name' => 'Verify', 'middle_name' => '', 'last_name' => 'Resident',
            'relation_to_household_head' => 'Head', 'birthday' => '1988-03-03', 'sex' => 'Female',
            'civil_status' => 'Married', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $jaicaId = DB::table('residents')->insertGetId([
            'household_id' => $householdId, 'first_name' => 'Jaica', 'middle_name' => 'A.', 'last_name' => 'Doe',
            'relation_to_household_head' => 'Daughter', 'birthday' => '2025-12-07', 'sex' => 'Female',
            'civil_status' => 'Single', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('resident_accounts')->where('account_id', $accountId)->update(['resident_id' => $headId]);
        $requestId = DB::table('record_requests')->insertGetId([
            'account_id' => $accountId, 'household_no_submitted' => 'HH-905', 'zone_submitted' => '1',
            'relationship_submitted' => 'Self', 'first_name_submitted' => 'Verify', 'middle_name_submitted' => '',
            'last_name_submitted' => 'Resident', 'mobile_number_submitted' => '09170000095',
            'email_submitted' => 'verify.resident@example.test', 'matched_resident_id' => $headId,
            'status' => RecordRequest::STATUS_APPROVED, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('record_request_otps')->insert([
            'request_id' => $requestId, 'channel' => 'sms', 'code_hash' => bcrypt('123456'),
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('timbang_records')->insert([
            'resident_id' => $jaicaId, 'measurement_date' => '2026-08-01', 'weight_kg' => 11.5, 'height_cm' => 87,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->get(route('chatbot.household.information'))->assertOk()->getContent();

        $this->assertStringContainsString('Jaica A. Doe', $html);
        $this->assertStringContainsString('December 7, 2025', $html);
        $this->assertMatchesRegularExpression('/Jaica A\. Doe[\s\S]{0,240}?8 months old/i', $html);
        $this->assertDoesNotMatchRegularExpression('/Jaica A\. Doe[\s\S]{0,240}?38 years old/i', $html);
        $this->assertStringContainsString('11.5 kg', $html);
        $this->assertStringContainsString('87 cm', $html);
    }

    public function test_layouts_expose_skip_to_main_content_link(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $adminHtml = $this->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        // household.information needs an approved request; the request form is the resident page every account can open.
        // The resident fixture swaps in ERD account tables, so it runs after the staff page.
        $this->actingAsErdResident();
        $chatbotHtml = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();

        foreach ([$chatbotHtml, $adminHtml] as $html) {
            $this->assertMatchesRegularExpression(
                '/<a[^>]*href="#main-content"[^>]*>\s*Skip to main content\s*<\/a>/i',
                $html
            );
            $this->assertStringContainsString('id="main-content"', $html);
            $this->assertStringContainsString('lml-skip-link', $html);
        }
    }
}
