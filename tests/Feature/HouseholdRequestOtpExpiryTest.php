<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Services\HouseholdRecordRequestOtpIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\HouseholdLinkingFixtures;
use Tests\TestCase;

/**
 * Panel refinement SR-04 (RSC S-04 / M-10): household-record OTP validity is 5 minutes, not 15.
 * Exercises the real staff-verified flow; SMS gateway faked, stray HTTP blocked, mail faked.
 */
class HouseholdRequestOtpExpiryTest extends TestCase
{
    use HouseholdLinkingFixtures;
    use RefreshDatabase;

    private const EMAIL = 'otp.expiry@example.test';

    private const VERIFIED_MOBILE = '09170000001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['lmlinga.household_linking.mode' => 'staff_verified']);
        $this->ensureLinkingSchema();
        $this->fakeSms();
        Http::preventStrayRequests();
        Mail::fake();
    }

    /**
     * Resident submits a matching request, an Admin verifies it, and the resident sends the SMS code.
     *
     * @return array{account_id: int, resident_id: int, request_id: int, code: string}
     */
    private function sendStaffVerifiedOtp(): array
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount(self::EMAIL);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload(self::EMAIL, [
            'mobileNumber' => self::VERIFIED_MOBILE,
        ]))->assertRedirect(route('chatbot.main'));
        $requestId = (int) $this->latestRequest($accountId)->request_id;

        $this->loginStaff($this->seedErdAdmin());
        $this->post(route('household-requests.verify-identity', ['id' => 'req-'.$requestId]), [
            'identity_verified' => '1',
            'mobile_verified' => '1',
        ])->assertSessionHas('status');
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $this->latestRequest($accountId)->status);

        $this->actingAsResident($accountId, self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $sent = $this->sentSms();
        $this->assertCount(1, $sent);
        $this->assertSame(self::VERIFIED_MOBILE, $sent[0]['number']);

        return [
            'account_id' => $accountId,
            'resident_id' => $household['resident_id'],
            'request_id' => $requestId,
            'code' => $sent[0]['code'],
        ];
    }

    public function test_issued_code_expires_exactly_five_minutes_after_it_is_sent(): void
    {
        $this->assertSame(5, HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES);

        $issuedAt = $this->freezeSecond();
        $otp = $this->sendStaffVerifiedOtp();

        $expiresAt = DB::table('record_request_otps')->where('request_id', $otp['request_id'])->value('expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertSame(
            $issuedAt->copy()->addMinutes(5)->getTimestamp(),
            now()->parse($expiresAt)->getTimestamp(),
            'The household-record OTP must be valid for exactly 5 minutes.'
        );
    }

    public function test_code_is_still_accepted_just_before_five_minutes(): void
    {
        $this->freezeSecond();
        $otp = $this->sendStaffVerifiedOtp();

        $this->travel(299)->seconds();

        $this->actingAsResident($otp['account_id'], self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $otp['code']]);

        $this->assertSame(RecordRequest::STATUS_APPROVED, $this->latestRequest($otp['account_id'])->status);
        $this->assertSame($otp['resident_id'], (int) $this->linkedResidentId($otp['account_id']));
    }

    public function test_code_is_rejected_after_five_minutes_and_grants_no_household_access(): void
    {
        $this->freezeSecond();
        $otp = $this->sendStaffVerifiedOtp();

        $this->travel(301)->seconds();

        $this->actingAsResident($otp['account_id'], self::EMAIL);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $otp['code']]);

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $this->latestRequest($otp['account_id'])->status);
        $this->assertNull($this->linkedResidentId($otp['account_id']));
        $this->assertNull(DB::table('record_request_otps')->where('request_id', $otp['request_id'])->value('verified_at'));
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
    }

    public function test_sms_page_countdown_starts_at_five_minutes(): void
    {
        $this->freezeSecond();
        $otp = $this->sendStaffVerifiedOtp();

        $this->actingAsResident($otp['account_id'], self::EMAIL);
        $this->get(route('chatbot.household.verification.sms'))
            ->assertOk()
            ->assertSee('The code will expire in', false)
            ->assertSee('<strong data-lml-otp-timer-value>05:00</strong>', false);
    }
}
