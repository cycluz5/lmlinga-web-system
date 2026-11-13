<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HouseholdLinkingFixtures;
use Tests\TestCase;

/**
 * Characterization of the original (legacy) automatic household-linking flow.
 * Pinned to household_linking.mode=legacy so it doubles as the rollback contract.
 */
class HouseholdLinkingCharacterizationTest extends TestCase
{
    use HouseholdLinkingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lmlinga.household_linking.mode' => 'legacy']);
        $this->ensureLinkingSchema();
        $this->fakeSms();
    }

    public function test_legacy_match_sends_otp_to_the_submitted_number_and_links_after_verify(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount('lina.legacy@example.test');
        $this->actingAsResident($accountId, 'lina.legacy@example.test');

        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('lina.legacy@example.test', [
            'mobileNumber' => '09179990000',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $record = $this->latestRequest($accountId);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $record->status);
        $this->assertSame($household['resident_id'], (int) $record->matched_resident_id);

        // The original weakness: the OTP goes to whatever number the requester typed.
        $sent = $this->sentSms();
        $this->assertCount(1, $sent);
        $this->assertSame('09179990000', $sent[0]['number']);

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $sent[0]['code']])
            ->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $this->latestRequest($accountId)->status);
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($accountId));
        $this->get(route('chatbot.household.information'))->assertOk();
    }

    public function test_legacy_name_mismatch_is_denied_without_sending_an_otp(): void
    {
        $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount('other.name@example.test', 'Other', 'Person', 'Name');
        $this->actingAsResident($accountId, 'other.name@example.test');

        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('other.name@example.test', [
            'firstName' => 'Other',
            'middleName' => 'Person',
            'lastName' => 'Name',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_DENIED, $this->latestRequest($accountId)->status);
        $this->assertSame([], $this->sentSms());
        $this->assertNull($this->linkedResidentId($accountId));
    }

    public function test_legacy_resident_already_linked_to_another_account_is_not_relinked(): void
    {
        $household = $this->seedLinkableHousehold();
        $ownerId = $this->createResidentAccount('owner@example.test');
        DB::table('resident_accounts')->where('account_id', $ownerId)->update(['resident_id' => $household['resident_id']]);

        $otherId = $this->createResidentAccount('copycat@example.test');
        $this->actingAsResident($otherId, 'copycat@example.test');
        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('copycat@example.test'));

        $sent = $this->sentSms();
        $this->assertCount(1, $sent);
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $sent[0]['code']]);

        $this->assertNull($this->linkedResidentId($otherId));
        $this->assertSame($household['resident_id'], (int) $this->linkedResidentId($ownerId));
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
    }

    public function test_unlinked_account_cannot_open_household_or_member_pages(): void
    {
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount('nobody@example.test', 'No', 'Body', 'Here');
        $this->actingAsResident($accountId, 'nobody@example.test');

        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        foreach (['chatbot.household.members.show', 'chatbot.household.members.child-care', 'chatbot.household.members.maternal',
            'chatbot.household.members.family-planning', 'chatbot.household.members.risk-assessment'] as $name) {
            $this->get(route($name, ['member' => $household['resident_id']]))->assertRedirect(route('chatbot.main'));
        }
    }

    public function test_linked_account_cannot_open_a_member_of_another_household(): void
    {
        $mine = $this->seedLinkableHousehold('121');
        $theirs = $this->seedLinkableHousehold('122', 'Ana', 'Reyes', 'Lopez');
        $accountId = $this->createResidentAccount('lina.cross@example.test');
        $this->actingAsResident($accountId, 'lina.cross@example.test');

        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('lina.cross@example.test'));
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $this->sentSms()[0]['code']]);
        $this->assertSame($mine['resident_id'], (int) $this->linkedResidentId($accountId));

        $this->get(route('chatbot.household.members.show', ['member' => $mine['resident_id']]))->assertOk();
        // ChatbotHouseholdMemberAccess aborts 404 for a member outside the linked household.
        $this->get(route('chatbot.household.members.show', ['member' => $theirs['resident_id']]))->assertNotFound();
    }

    public function test_account_cannot_read_another_accounts_conversation(): void
    {
        // The chatbot-tables migration skips itself under unit tests; create the table here.
        Schema::create('chatbot_conversations', function (Blueprint $table): void {
            $table->id('conversation_id');
            $table->unsignedBigInteger('account_id');
            $table->string('title', 150)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        $ownerId = $this->createResidentAccount('conv.owner@example.test');
        $otherId = $this->createResidentAccount('conv.other@example.test', 'Conv', 'O', 'Other');
        $conversationId = (int) DB::table('chatbot_conversations')->insertGetId([
            'account_id' => $ownerId,
            'title' => 'Private question',
            'is_pinned' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsResident($otherId, 'conv.other@example.test');
        $this->getJson(route('chatbot.conversation.history', ['conversationId' => $conversationId]))->assertNotFound();
        $this->deleteJson(route('chatbot.conversation.destroy', ['conversationId' => $conversationId]))->assertNotFound();
        $this->assertSame(1, DB::table('chatbot_conversations')->where('conversation_id', $conversationId)->count());
    }

    public function test_legacy_approved_without_otp_shows_continue_verification(): void
    {
        // Original behaviour for the rollback contract: legacy Approved-without-OTP re-enters OTP itself.
        $household = $this->seedLinkableHousehold();
        $accountId = $this->createResidentAccount('legacy.approved@example.test');
        DB::table('record_requests')->insert([
            'account_id' => $accountId, 'household_no_submitted' => '121', 'zone_submitted' => '1',
            'relationship_submitted' => 'Household Head', 'first_name_submitted' => 'Lina', 'middle_name_submitted' => 'Cruz',
            'last_name_submitted' => 'Santos', 'mobile_number_submitted' => '09179990000', 'email_submitted' => 'legacy.approved@example.test',
            'matched_resident_id' => $household['resident_id'], 'status' => RecordRequest::STATUS_APPROVED,
            'evaluated_at' => now(), 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAsResident($accountId, 'legacy.approved@example.test');
        $this->get(route('chatbot.main'))->assertOk()->assertSee('Continue Verification');
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
    }
}
