<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\HouseholdLinkingFixtures;
use Tests\TestCase;

/**
 * SR-01 autofill, scope A: the household record request form prefills first/middle/last name from the
 * signed-in resident's own account. Editable; submitted (old) input always wins; never another
 * resident's data; matching and staff verification unchanged.
 */
class HouseholdRequestNamePrefillTest extends TestCase
{
    use HouseholdLinkingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lmlinga.household_linking.mode' => 'staff_verified']);
        $this->ensureLinkingSchema();
        $this->fakeSms();
        Mail::fake();
    }

    private function formHtml(): string
    {
        return $this->get(route('chatbot.household.verification'))->assertOk()->getContent();
    }

    private function assertInputValue(string $html, string $name, string $expected): void
    {
        $this->assertSame(1, preg_match('/<input[^>]*name="'.$name.'"[^>]*>/s', $html, $m), "input $name present");
        $this->assertStringContainsString('value="'.e($expected).'"', $m[0], "$name value");
    }

    public function test_names_are_prefilled_from_the_signed_in_account_and_stay_editable(): void
    {
        $accountId = $this->createResidentAccount('prefill.one@example.test', 'Lina', 'Cruz', 'Santos');
        $this->actingAsResident($accountId, 'prefill.one@example.test');

        $html = $this->formHtml();

        $this->assertInputValue($html, 'firstName', 'Lina');
        $this->assertInputValue($html, 'middleName', 'Cruz');
        $this->assertInputValue($html, 'lastName', 'Santos');
        $this->assertStringContainsString('data-lml-name-prefill-note', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="(firstName|middleName|lastName)"[^>]*(readonly|disabled)/s', $html);
        // Only names are prefilled (approved scope A).
        $this->assertInputValue($html, 'householdNo', '');
        $this->assertInputValue($html, 'mobileNumber', '');
        $this->assertInputValue($html, 'emailAddress', '');
    }

    public function test_submitted_values_are_never_overwritten_after_a_validation_error(): void
    {
        $accountId = $this->createResidentAccount('prefill.two@example.test', 'Lina', 'Cruz', 'Santos');
        $this->actingAsResident($accountId, 'prefill.two@example.test');

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->requestPayload('prefill.two@example.test', [
                'firstName' => 'Carolina', 'middleName' => '', 'lastName' => 'Delos Santos', 'householdNo' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors(['householdNo', 'middleName']);

        $html = $this->formHtml();
        $this->assertInputValue($html, 'firstName', 'Carolina');
        $this->assertInputValue($html, 'lastName', 'Delos Santos');
        $this->assertInputValue($html, 'middleName', '');
    }

    public function test_missing_or_blank_account_names_prefill_safely(): void
    {
        $accountId = $this->createResidentAccount('prefill.three@example.test', '  Ana  ', 'x', 'Reyes');
        DB::table('resident_accounts')->where('account_id', $accountId)->update(['middle_name' => null]);
        $this->actingAsResident($accountId, 'prefill.three@example.test');

        $html = $this->formHtml();
        $this->assertInputValue($html, 'firstName', 'Ana');
        $this->assertInputValue($html, 'middleName', '');
        $this->assertInputValue($html, 'lastName', 'Reyes');
    }

    public function test_another_residents_names_are_never_shown(): void
    {
        $this->createResidentAccount('other.resident@example.test', 'Private', 'Other', 'Person');
        $mine = $this->createResidentAccount('prefill.four@example.test', 'Ben', 'Santos', 'Kunwari');
        $this->actingAsResident($mine, 'prefill.four@example.test');

        $html = $this->formHtml();
        $this->assertInputValue($html, 'firstName', 'Ben');
        $this->assertStringNotContainsString('Private', $html);
        $this->assertStringNotContainsString('Person', $html);
    }

    public function test_prefilled_names_do_not_bypass_matching_or_staff_verification(): void
    {
        $this->seedLinkableHousehold('121', 'Lina', 'Cruz', 'Santos');
        $accountId = $this->createResidentAccount('prefill.five@example.test', 'Lina', 'Cruz', 'Santos');
        $this->actingAsResident($accountId, 'prefill.five@example.test');

        $this->post(route('chatbot.household.verification.store'), $this->requestPayload('prefill.five@example.test'))
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame('Pending', $this->latestRequest($accountId)->status);
        $this->assertSame([], $this->sentSms(), 'No OTP before staff verification.');
        $this->assertNull($this->linkedResidentId($accountId));
    }

    public function test_guests_cannot_open_the_form(): void
    {
        $this->get(route('chatbot.household.verification'))->assertRedirect(route('chatbot.login'));
    }
}
