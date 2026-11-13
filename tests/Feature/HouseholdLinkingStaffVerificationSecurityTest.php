<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Support\HouseholdLinkingPolicy;
use App\Support\HouseholdRecordRequestMatcher;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\HouseholdLinkingFixtures;
use Tests\TestCase;

/**
 * P1 — staff-verified household linking (household_linking.mode=staff_verified, the default).
 * An OTP is never sent to a number staff have not verified; links need Admin verification.
 */
class HouseholdLinkingStaffVerificationSecurityTest extends TestCase
{
    use HouseholdLinkingFixtures;
    use RefreshDatabase;

    private const EMAIL = 'lina.resident@example.test';

    private const VERIFIED_MOBILE = '09170000001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['lmlinga.household_linking.mode' => 'staff_verified']);
        $this->ensureLinkingSchema();
        $this->fakeSms();
        Mail::fake();
    }

    private function submitMatchingRequest(int $accountId, string $email = self::EMAIL, array $overrides = []): object
    {
        $this->actingAsResident($accountId, $email);
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload($email, $overrides + [
            'mobileNumber' => self::VERIFIED_MOBILE,
        ]))->assertRedirect(route('chatbot.main'));

        return $this->latestRequest($accountId);
    }

    private function staffVerify(int $requestId, array $attest = ['identity_verified' => '1', 'mobile_verified' => '1'])
    {
        return $this->post(route('household-requests.verify-identity', ['id' => 'req-'.$requestId]), $attest);
    }

    private function sendAndVerifyOtp(int $accountId, string $email = self::EMAIL): void
    {
        $this->actingAsResident($accountId, $email);
        $this->post(route('chatbot.household.verification.sms.send'))->assertRedirect(route('chatbot.household.verification.sms'));
        $sent = $this->sentSms();
        $this->assertNotEmpty($sent);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => end($sent)['code']]);
    }

    // ---------------------------------------------------------------- legitimate linking

    public function test_matched_request_waits_for_staff_and_no_otp_is_sent_to_the_submitted_number(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);

        $record = $this->submitMatchingRequest($accountId);

        $this->assertSame(RecordRequest::STATUS_PENDING, $record->status);
        $this->assertSame($household['resident_id'], (int) $record->matched_resident_id);
        $this->assertSame(HouseholdLinkingPolicy::REASON_AWAITING_STAFF, $record->decision_reason);
        $this->assertSame([], $this->sentSms());
        $this->assertNull($this->linkedResidentId($accountId));

        $this->get(route('chatbot.household.verification.status'))
            ->assertOk()
            ->assertSee('Awaiting staff verification')
            ->assertSee('valid ID');
    }

    public function test_admin_verified_request_links_after_otp_sent_only_to_the_verified_number(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);

        $this->loginStaff($this->seedErdAdmin());
        $this->get(route('household-requests.view', ['id' => 'req-'.$record->request_id]))
            ->assertOk()
            ->assertSee('data-hr-staff-verify-form', false);
        $this->staffVerify((int) $record->request_id)->assertSessionHas('status');

        $verified = $this->latestRequest($accountId);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $verified->status);
        $this->assertSame(HouseholdLinkingPolicy::REASON_STAFF_VERIFIED, $verified->decision_reason);
        $this->assertSame([], $this->sentSms(), 'Staff verification itself must not send anything.');

        $this->sendAndVerifyOtp($accountId);

        foreach ($this->sentSms() as $sms) {
            $this->assertSame(self::VERIFIED_MOBILE, $sms['number']);
        }
        $this->assertSame(RecordRequest::STATUS_APPROVED, $this->latestRequest($accountId)->status);
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($accountId));
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertOk();
    }

    // ---------------------------------------------------------------- unauthorized linking

    public function test_resident_cannot_open_send_or_verify_an_otp_before_staff_verification(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $this->submitMatchingRequest($accountId);

        $this->get(route('chatbot.household.verification.sms'))->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.sms.send'))->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '123456'])->assertRedirect(route('chatbot.main'));

        $this->assertSame([], $this->sentSms());
        $this->assertNull($this->linkedResidentId($accountId));
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
    }

    public function test_email_otp_is_not_available_for_linking_even_after_staff_verification(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.household.verification.sms'))
            ->assertOk()
            ->assertDontSee('Send via Email');
        $this->get(route('chatbot.household.verification.email'))->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.email.send'))->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '123456'])->assertRedirect(route('chatbot.main'));

        Mail::assertNothingOutgoing();
        $this->assertNull($this->linkedResidentId($accountId));
    }

    public function test_legacy_awaiting_otp_request_needs_staff_verification_and_its_old_code_is_void(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $requestId = (int) DB::table('record_requests')->insertGetId([
            'account_id' => $accountId, 'household_no_submitted' => '121', 'zone_submitted' => '1',
            'relationship_submitted' => 'Household Head', 'first_name_submitted' => 'Lina', 'middle_name_submitted' => 'Cruz',
            'last_name_submitted' => 'Santos', 'mobile_number_submitted' => '09179990000', 'email_submitted' => self::EMAIL,
            'matched_resident_id' => $household['resident_id'], 'status' => RecordRequest::STATUS_AWAITING_OTP,
            'decision_reason' => HouseholdRecordRequestMatcher::REASON_AWAITING_OTP,
            'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('record_request_otps')->insert([
            'request_id' => $requestId, 'channel' => 'sms', 'code_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(10), 'last_sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '654321'])->assertRedirect(route('chatbot.main'));
        $this->assertNull($this->linkedResidentId($accountId));

        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify($requestId)->assertSessionHas('status');
        $this->assertNotNull(DB::table('record_request_otps')->where('request_id', $requestId)->value('invalidated_at'));

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '654321']);
        $this->assertNull($this->linkedResidentId($accountId), 'A code issued before staff verification must not link.');
    }

    public function test_staff_verification_requires_both_attestations(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());

        $this->staffVerify((int) $record->request_id, [])->assertSessionHasErrors(['identity_verified', 'mobile_verified']);
        $this->staffVerify((int) $record->request_id, ['identity_verified' => '1'])->assertSessionHasErrors(['mobile_verified']);

        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($accountId)->status);
    }

    public function test_only_admins_can_verify_decline_or_revoke(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $id = 'req-'.$record->request_id;

        // Resident session only (no staff login): staff routes send guests to /login.
        foreach (['household-requests.verify-identity', 'household-requests.decline', 'household-requests.revoke-link'] as $name) {
            $this->post(route($name, ['id' => $id]), ['identity_verified' => '1', 'mobile_verified' => '1'])
                ->assertRedirect(route('login'));
        }

        $this->loginStaff($this->seedErdStaff('linking.bhw', StaffRole::BHW));
        foreach (['household-requests.verify-identity', 'household-requests.decline', 'household-requests.revoke-link'] as $name) {
            $this->post(route($name, ['id' => $id]), ['identity_verified' => '1', 'mobile_verified' => '1'])->assertForbidden();
        }

        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($accountId)->status);
    }

    public function test_raw_request_ids_are_rejected_on_staff_routes(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());

        $this->post('/household-requests/req-'.$record->request_id.'/verify-identity', ['identity_verified' => '1', 'mobile_verified' => '1'])
            ->assertNotFound();
        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($accountId)->status);
    }

    public function test_denials_do_not_reveal_which_detail_failed(): void
    {
        $this->seedLinkableHousehold();

        $unknownHousehold = $this->createResidentAccount('unknown.hh@example.test');
        $this->actingAsResident($unknownHousehold, 'unknown.hh@example.test');
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('unknown.hh@example.test', ['householdNo' => '999']));

        $wrongMember = $this->createResidentAccount('wrong.member@example.test', 'Wrong', 'Member', 'Name');
        $this->actingAsResident($wrongMember, 'wrong.member@example.test');
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('wrong.member@example.test', [
            'firstName' => 'Wrong', 'middleName' => 'Member', 'lastName' => 'Name',
        ]));

        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $this->latestRequest($unknownHousehold)->decision_reason);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $this->latestRequest($wrongMember)->decision_reason);
        $this->assertSame([], $this->sentSms());
    }

    // ---------------------------------------------------------------- duplicate linking

    public function test_staff_cannot_verify_a_request_for_a_resident_already_linked_to_another_account(): void
    {
        $household = $this->seedLinkableHousehold();
        $ownerId = $this->createResidentAccount('owner@example.test');
        DB::table('resident_accounts')->where('account_id', $ownerId)->update(['resident_id' => $household['resident_id']]);

        $otherId = $this->createResidentAccount('second@example.test');
        $record = $this->submitMatchingRequest($otherId, 'second@example.test');

        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id)->assertSessionHas('error');

        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($otherId)->status);
        $this->assertNull($this->linkedResidentId($otherId));
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($ownerId));
    }

    public function test_otp_cannot_link_a_resident_that_was_linked_elsewhere_after_staff_verification(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);

        $ownerId = $this->createResidentAccount('late.owner@example.test');
        DB::table('resident_accounts')->where('account_id', $ownerId)->update(['resident_id' => $household['resident_id']]);

        $this->sendAndVerifyOtp($accountId);

        $this->assertNull($this->linkedResidentId($accountId));
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($ownerId));
    }

    // ---------------------------------------------------------------- cross-account / existing links

    public function test_account_cannot_see_another_accounts_household_or_request_status(): void
    {
        $household = $this->seedLinkableHousehold();
        $ownerId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($ownerId);
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);
        $this->sendAndVerifyOtp($ownerId);
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($ownerId));

        $strangerId = $this->createResidentAccount('stranger@example.test', 'Some', 'Other', 'Person');
        $this->actingAsResident($strangerId, 'stranger@example.test');
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.members.maternal', ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.verification.status'))->assertRedirect(route('chatbot.household.verification'));
    }

    public function test_previously_linked_accounts_keep_access_unchanged(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        DB::table('resident_accounts')->where('account_id', $accountId)->update(['resident_id' => $household['resident_id']]);
        $requestId = (int) DB::table('record_requests')->insertGetId([
            'account_id' => $accountId, 'household_no_submitted' => '121', 'zone_submitted' => '1',
            'relationship_submitted' => 'Household Head', 'first_name_submitted' => 'Lina', 'middle_name_submitted' => 'Cruz',
            'last_name_submitted' => 'Santos', 'mobile_number_submitted' => self::VERIFIED_MOBILE, 'email_submitted' => self::EMAIL,
            'matched_resident_id' => $household['resident_id'], 'status' => RecordRequest::STATUS_APPROVED,
            'decision_reason' => HouseholdRecordRequestMatcher::REASON_AWAITING_OTP,
            'evaluated_at' => now(), 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('record_request_otps')->insert([
            'request_id' => $requestId, 'channel' => 'sms', 'code_hash' => Hash::make('111111'),
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertOk();
    }

    // ---------------------------------------------------------------- recovery

    public function test_staff_decline_uses_a_generic_reason_and_voids_open_codes(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());

        $this->post(route('household-requests.decline', ['id' => 'req-'.$record->request_id]))->assertSessionHas('status');

        $declined = $this->latestRequest($accountId);
        $this->assertSame(RecordRequest::STATUS_DENIED, $declined->status);
        $this->assertSame(HouseholdLinkingPolicy::REASON_STAFF_DECLINED, $declined->decision_reason);
        $this->assertNull($this->linkedResidentId($accountId));
    }

    public function test_revoke_removes_access_immediately_and_the_resident_can_verify_again(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);
        $this->sendAndVerifyOtp($accountId);
        $this->get(route('chatbot.household.information'))->assertOk();

        $this->post(route('household-requests.revoke-link', ['id' => 'req-'.$record->request_id]))->assertSessionHas('status');

        $this->assertNull($this->linkedResidentId($accountId));
        $this->assertSame(RecordRequest::STATUS_DENIED, $this->latestRequest($accountId)->status);
        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));

        // Re-verification path: a new request goes back to staff.
        $again = $this->submitMatchingRequest($accountId);
        $this->assertSame(HouseholdLinkingPolicy::REASON_AWAITING_STAFF, $again->decision_reason);
    }

    // ---------------------------------------------------------------- rate limits

    public function test_account_daily_request_cap_is_configurable_and_shows_the_limit(): void
    {
        config(['lmlinga.household_linking.max_requests_per_account_per_day' => 2]);
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount('cap@example.test', 'Cap', 'Test', 'Person');
        $this->actingAsResident($accountId, 'cap@example.test');
        $payload = $this->requestPayload('cap@example.test', ['firstName' => 'Cap', 'middleName' => 'Test', 'lastName' => 'Person']);

        $this->post(route('chatbot.household.verification.store'), $payload); // denied (no such member)
        $this->post(route('chatbot.household.verification.store'), $payload); // denied
        $this->post(route('chatbot.household.verification.store'), $payload)
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHas('household_request_limit_reached', true);

        $this->assertSame(2, DB::table('record_requests')->where('account_id', $accountId)->count());
        $this->get(route('chatbot.household.verification'))->assertOk()->assertSee('Daily request limit reached');

        $this->travel(25)->hours();
        $this->post(route('chatbot.household.verification.store'), $payload);
        $this->assertSame(3, DB::table('record_requests')->where('account_id', $accountId)->count());
    }

    public function test_denied_attempts_do_not_lock_a_household_out(): void
    {
        config(['lmlinga.household_linking.max_requests_per_household_per_day' => 2]);
        $household = $this->seedLinkableHousehold();

        // Three bogus (denied) submissions naming household 121 from different accounts.
        foreach (['a', 'b', 'c'] as $suffix) {
            $id = $this->createResidentAccount("guess.$suffix@example.test", 'Guess', $suffix, 'Er');
            $this->actingAsResident($id, "guess.$suffix@example.test");
            $this->post(route('chatbot.household.verification.store'), $this->requestPayload("guess.$suffix@example.test", [
                'firstName' => 'Guess', 'middleName' => $suffix, 'lastName' => 'Er',
            ]));
        }

        // The genuine resident can still submit and reach the staff queue.
        $genuine = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($genuine);
        $this->assertSame(HouseholdLinkingPolicy::REASON_AWAITING_STAFF, $record->decision_reason);
        $this->assertSame($household['resident_id'], (int) $record->matched_resident_id);
    }

    public function test_household_staff_queue_cap_limits_pending_requests_across_accounts(): void
    {
        config(['lmlinga.household_linking.max_requests_per_household_per_day' => 2]);
        $household = $this->seedLinkableHousehold();
        foreach ([['Ana', 'Cruz'], ['Ben', 'Cruz'], ['Cora', 'Cruz']] as [$first, $middle]) {
            DB::table('residents')->insert([
                'household_id' => $household['household_id'], 'first_name' => $first, 'middle_name' => $middle,
                'last_name' => 'Santos', 'relation_to_household_head' => 'Daughter', 'birthday' => '2001-01-01',
                'sex' => 'Female', 'civil_status' => 'Single', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $ids = [];
        foreach (['Ana', 'Ben', 'Cora'] as $first) {
            $email = strtolower($first).'@example.test';
            $ids[$first] = $this->createResidentAccount($email, $first, 'Cruz', 'Santos');
            $this->actingAsResident($ids[$first], $email);
            $this->post(route('chatbot.household.verification.store'), $this->requestPayload($email, [
                'firstName' => $first, 'middleName' => 'Cruz', 'lastName' => 'Santos',
            ]));
        }

        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($ids['Ana'])->status);
        $this->assertSame(RecordRequest::STATUS_PENDING, $this->latestRequest($ids['Ben'])->status);
        $this->assertNull($this->latestRequest($ids['Cora']), 'Third pending request for one household is held back.');
    }

    // ---------------------------------------------------------------- duplicate requests / UI flow

    public function test_a_new_submission_cannot_displace_a_staff_verified_request(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL, [
            'mobileNumber' => '09995554444',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame(1, DB::table('record_requests')->where('account_id', $accountId)->count());
        $this->assertSame(self::VERIFIED_MOBILE, $this->latestRequest($accountId)->mobile_number_submitted);
        $this->get(route('chatbot.household.verification'))->assertRedirect(route('chatbot.household.verification.sms'));
    }

    public function test_main_and_status_pages_follow_staff_verification(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);

        // Waiting for staff: no OTP entry point anywhere.
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Request Sent')->assertDontSee('Continue Verification');
        $this->get(route('chatbot.household.verification'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.verification.status'))->assertOk()->assertDontSee('data-hh-continue-otp', false);

        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $record->request_id);

        // After staff verification the resident is pointed at the SMS step.
        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Continue Verification');
        $this->get(route('chatbot.household.verification.status'))
            ->assertOk()
            ->assertSee('Identity verified')
            ->assertSee('data-hh-continue-otp', false);
        $this->get(route('chatbot.household.verification.sms'))->assertOk();
    }

    public function test_legacy_unverified_otp_request_shows_request_sent_not_a_dead_end_button(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        DB::table('record_requests')->insert([
            'account_id' => $accountId, 'household_no_submitted' => '121', 'zone_submitted' => '1',
            'relationship_submitted' => 'Household Head', 'first_name_submitted' => 'Lina', 'middle_name_submitted' => 'Cruz',
            'last_name_submitted' => 'Santos', 'mobile_number_submitted' => '09179990000', 'email_submitted' => self::EMAIL,
            'matched_resident_id' => $household['resident_id'], 'status' => RecordRequest::STATUS_AWAITING_OTP,
            'decision_reason' => HouseholdRecordRequestMatcher::REASON_AWAITING_OTP,
            'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Request Sent')->assertDontSee('Continue Verification');
        $this->get(route('chatbot.household.verification'))->assertRedirect(route('chatbot.main'));
    }

    public function test_staff_declined_resident_can_submit_again(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $record = $this->submitMatchingRequest($accountId);
        $this->loginStaff($this->seedErdAdmin());
        $this->post(route('household-requests.decline', ['id' => 'req-'.$record->request_id]));

        $again = $this->submitMatchingRequest($accountId);
        $this->assertNotSame((int) $record->request_id, (int) $again->request_id);
        $this->assertSame(HouseholdLinkingPolicy::REASON_AWAITING_STAFF, $again->decision_reason);
    }

    // ---------------------------------------------------------------- rate-limit isolation (audit F1)

    public function test_rate_limits_do_not_share_counters_across_routes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('chatbot.register.store'), [])->assertStatus(302);
        }
        $this->post(route('chatbot.register.store'), [])->assertStatus(429);

        // Same IP: forgot-password and a resident's request submission are unaffected.
        $this->post(route('chatbot.password.email'), ['email' => 'nobody@example.test'])->assertStatus(302);
        $accountId = $this->createResidentAccount(self::EMAIL);
        $this->seedLinkableHousehold();
        $this->submitMatchingRequest($accountId);
    }

    public function test_submission_limit_is_per_resident_account_not_per_ip(): void
    {
        $first = $this->createResidentAccount('first.limit@example.test', 'First', 'L', 'Imit');
        $this->actingAsResident($first, 'first.limit@example.test');
        $payload = $this->requestPayload('first.limit@example.test', ['firstName' => 'First', 'middleName' => 'L', 'lastName' => 'Imit']);
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('chatbot.household.verification.store'), $payload)->assertStatus(302);
        }
        $this->post(route('chatbot.household.verification.store'), $payload)->assertStatus(429);

        // A different resident on the same IP still has their own budget.
        $second = $this->createResidentAccount('second.limit@example.test', 'Second', 'L', 'Imit');
        $this->actingAsResident($second, 'second.limit@example.test');
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('second.limit@example.test', [
            'firstName' => 'Second', 'middleName' => 'L', 'lastName' => 'Imit',
        ]))->assertStatus(302);
    }

    public function test_submission_endpoint_is_throttled(): void
    {
        $accountId = $this->createResidentAccount('throttle@example.test', 'Throttle', 'T', 'Test');
        $this->actingAsResident($accountId, 'throttle@example.test');
        $payload = $this->requestPayload('throttle@example.test', ['firstName' => 'Throttle', 'middleName' => 'T', 'lastName' => 'Test']);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('chatbot.household.verification.store'), $payload)->assertStatus(302);
        }
        $this->post(route('chatbot.household.verification.store'), $payload)->assertStatus(429);
    }

    public function test_registration_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('chatbot.register.store'), [])->assertStatus(302);
        }
        $this->post(route('chatbot.register.store'), [])->assertStatus(429);
    }

    // ---------------------------------------------------------------- legacy Approved-without-OTP recovery

    private function seedLegacyApprovedWithoutOtp(int $accountId, int $residentId): int
    {
        return (int) DB::table('record_requests')->insertGetId([
            'account_id' => $accountId, 'household_no_submitted' => '121', 'zone_submitted' => '1',
            'relationship_submitted' => 'Household Head', 'first_name_submitted' => 'Lina', 'middle_name_submitted' => 'Cruz',
            'last_name_submitted' => 'Santos', 'mobile_number_submitted' => '09179990000', 'email_submitted' => self::EMAIL,
            'matched_resident_id' => $residentId, 'status' => RecordRequest::STATUS_APPROVED,
            'decision_reason' => 'The submitted household information passed the required completeness and validation checks.',
            'evaluated_at' => now(), 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_legacy_approved_without_otp_resubmits_for_staff_verification_and_is_never_granted_automatically(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        $legacyId = $this->seedLegacyApprovedWithoutOtp($accountId, $household['resident_id']);
        $this->actingAsResident($accountId, self::EMAIL);

        // Recovery entry points are visible; nothing is granted.
        $this->get(route('chatbot.main'))->assertOk()
            ->assertSee('Request Household Record')
            ->assertDontSee('Request Sent')
            ->assertDontSee('Continue Verification');
        $this->get(route('chatbot.household.verification.status'))->assertOk()
            ->assertSee('Verification required')
            ->assertSee('href="'.e(route('chatbot.household.verification')).'"', false)
            ->assertDontSee('household access is active');
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.members.show', ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.sms.send'))->assertRedirect(route('chatbot.main'));
        $this->assertSame([], $this->sentSms());

        // Resubmission goes to the staff queue; still no OTP and no access.
        $this->get(route('chatbot.household.verification'))->assertOk();
        $again = $this->submitMatchingRequest($accountId);
        $this->assertNotSame($legacyId, (int) $again->request_id);
        $this->assertSame(HouseholdLinkingPolicy::REASON_AWAITING_STAFF, $again->decision_reason);
        $this->assertSame([], $this->sentSms());
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));

        // The legacy row is left as it was (no data rewrite).
        $this->assertSame(RecordRequest::STATUS_APPROVED, DB::table('record_requests')->where('request_id', $legacyId)->value('status'));

        // Normal completion: staff verification -> SMS to the verified number -> access.
        $this->loginStaff($this->seedErdAdmin());
        $this->staffVerify((int) $again->request_id)->assertSessionHas('status');
        $this->sendAndVerifyOtp($accountId);
        foreach ($this->sentSms() as $sms) {
            $this->assertSame(self::VERIFIED_MOBILE, $sms['number']);
        }
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($accountId));
        $this->get(route('chatbot.household.information'))->assertOk();
    }

    public function test_legacy_approved_without_otp_with_a_stale_link_still_has_no_access(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);
        DB::table('resident_accounts')->where('account_id', $accountId)->update(['resident_id' => $household['resident_id']]);
        $this->seedLegacyApprovedWithoutOtp($accountId, $household['resident_id']);
        $this->actingAsResident($accountId, self::EMAIL);

        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        foreach (['chatbot.household.members.show', 'chatbot.household.members.child-care', 'chatbot.household.members.maternal',
            'chatbot.household.members.family-planning', 'chatbot.household.members.risk-assessment'] as $name) {
            $this->get(route($name, ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
        }
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Request Household Record');
    }
}
