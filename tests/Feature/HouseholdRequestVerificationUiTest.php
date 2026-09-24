<?php

namespace Tests\Feature;

use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ErdRecordRequestsSchema;
use Tests\TestCase;

class HouseholdRequestVerificationUiTest extends TestCase
{
    use RefreshDatabase;
    public function test_resident_request_form_describes_automatic_verification(): void
    {
        $html = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('automatically compare', $html);
        $this->assertStringContainsString('Matching is automatic', $html);
        $this->assertStringContainsString('not a manual Admin approval step', $html);
        $this->assertStringContainsString('Submit Request', $html);
        $this->assertStringContainsString('chatbot/household/verification/status', $html);
        $this->assertStringNotContainsString('reviewed before your identity', $html);
    }

    public function test_daily_limit_state_hides_submit_action(): void
    {
        $html = $this->get(route('chatbot.household.verification', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Daily request limit reached', $html);
        $this->assertStringContainsString('maximum number of household record requests allowed today', $html);
        $this->assertStringNotContainsString('data-lml-household-request-form', $html);
        $this->assertStringNotContainsString('>Submit Request</', $html);
    }

    public function test_status_page_renders_automatic_verification_states(): void
    {
        $states = [
            'verifying' => 'Verification in progress',
            'approved' => 'Match found',
            'rejected' => 'No match found',
            'failed-1' => 'Failed attempt 1 of 3',
            'failed-2' => 'Failed attempt 2 of 3',
            'failed-3' => 'Failed attempt 3 of 3',
            'daily-limit' => 'Daily request limit reached',
        ];

        foreach ($states as $state => $label) {
            $html = $this->get(route('chatbot.household.verification.status', ['state' => $state]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString('automatic verification', strtolower($html));
            $this->assertStringNotContainsString('>Skip</', $html);
            $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Skip\s*<\/button>/i', $html);
            $this->assertStringNotContainsString('Approve', $html);
            $this->assertStringNotContainsString('Reject request', $html);
        }

        $verifying = $this->get(route('chatbot.household.verification.status', ['state' => 'verifying']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('compared with barangay records', $verifying);
        $this->assertStringContainsString('does not require Admin review', $verifying);

        $approved = $this->get(route('chatbot.household.verification.status', ['state' => 'approved']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('matched a household record', $approved);

        $rejected = $this->get(route('chatbot.household.verification.status', ['state' => 'rejected']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('could not find a matching household record', $rejected);

        $blocked = $this->get(route('chatbot.household.verification.status', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Try again', $blocked);
        $this->assertStringContainsString('Submit Request is unavailable', $blocked);
        $this->assertStringContainsString('maximum number of household record requests allowed today', $blocked);
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
        $chatbotHtml = $this->get(route('chatbot.household.information'))->assertOk()->getContent();
        $this->actingAsStaff(StaffRole::ADMIN);
        $adminHtml = $this->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

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
