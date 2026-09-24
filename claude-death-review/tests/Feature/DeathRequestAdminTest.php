<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\ResidentStatus;
use App\Support\DemoCatalog;
use App\Support\ResidentVitalStatus;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeathRequestAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_authorized_admin_can_list_death_requests(): void
    {
        $this->submitPending();

        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('death-requests.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Death Requests', $html);
        $this->assertStringContainsString('lml-topbar__title', $html);
        $this->assertStringContainsString(
            'Review submitted death records before a resident is marked deceased.',
            $html
        );
        $this->assertStringNotContainsString('id="lml-dr-heading"', $html);
        $this->assertStringNotContainsString('lml-dr__title', $html);
        $this->assertStringNotContainsString(
            'Review submitted death records. Approval marks the resident deceased.',
            $html
        );
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('>Resident Name</th>', $html);
        $this->assertStringContainsString('>Status</th>', $html);
        $this->assertStringContainsString('>Action</th>', $html);
        $this->assertStringContainsString('id="lml-dr-search"', $html);
        $this->assertStringContainsString('placeholder="Search Resident"', $html);
        $this->assertStringContainsString('id="lml-dr-status"', $html);
        $this->assertStringContainsString('All Statuses', $html);
        $this->assertStringNotContainsString('All Zones', $html);
        $this->assertStringNotContainsString('id="lml-dr-zone"', $html);
        $this->assertStringNotContainsString('data-dr-zone', $html);
        $this->assertMatchesRegularExpression(
            '/lml-dr__cell--name[^>]*>\s*<span class="lml-dr__identity-name">Kristine Reyes<\/span>\s*<\/td>/u',
            $html
        );
        $this->assertStringNotContainsString('lml-hr-death__resident-meta', $html);
        $this->assertStringNotContainsString('>Household / Zone</th>', $html);
        $this->assertStringNotContainsString('>Date of Death</th>', $html);
        $this->assertStringNotContainsString('>Cause of Death</th>', $html);
        $this->assertStringNotContainsString('>Registry No.</th>', $html);
        $this->assertStringNotContainsString('>Certificate No.</th>', $html);
        $this->assertStringNotContainsString('>Submitted By</th>', $html);
        $this->assertStringNotContainsString('>Submitted On</th>', $html);
        $this->assertStringNotContainsString('Cardiac arrest', $html);
        $this->assertStringNotContainsString('2026-00123', $html);
        $this->assertStringNotContainsString('DC-2026-00451', $html);
        $this->assertStringNotContainsString('HH 151', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-dr__cell--name[\s\S]{0,220}?(Male|Female|MB-\d+|Household|Zone 2)/u',
            $html
        );
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringContainsString('Review', $html);
        $this->assertStringContainsString('lml-dr__review-btn', $html);
        $this->assertStringContainsString('lml-dr__table', $html);
        $this->assertStringContainsString(
            'aria-label="Review death request for Kristine Reyes, MB-002"',
            $html
        );
        $this->assertStringNotContainsString('certificate.pdf', $html);
    }

    public function test_unauthorized_user_cannot_access_death_requests(): void
    {
        $this->submitPending();
        $id = DeathRequest::query()->value('id');

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('death-requests.index'))
            ->assertForbidden();

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('death-requests.show', $id))
            ->assertForbidden();

        $this->withSession([UiRole::SESSION_KEY => 'bns'])
            ->post(route('death-requests.approve', $id))
            ->assertForbidden();

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('death-requests.reject', $id), [
                'rejection_reason' => 'Should not be allowed.',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_review_pending_request(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $response = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('death-requests.show', $request));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Verify Death Record', $html);
        $this->assertStringContainsString('lml-topbar__title', $html);
        $this->assertStringContainsString(
            'Review the submitted death record and supporting certificate.',
            $html
        );
        $this->assertStringNotContainsString('lml-dr-verify__title', $html);
        $this->assertStringNotContainsString('id="lml-dr-verify-heading"', $html);
        $this->assertStringContainsString('aria-label="Back to Death Requests"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/lml-dr-verify__back[^>]*>\s*<i[^>]*><\/i>\s*Death Requests/u',
            $html
        );
        $this->assertStringContainsString('Pending verification', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('Household No.', $html);
        $this->assertStringContainsString('HH 151', $html);
        $this->assertStringContainsString('>Zone</dt>', $html);
        $this->assertStringContainsString('Zone 2', $html);
        $this->assertStringNotContainsString('Household HH 151 · Zone 2', $html);
        $this->assertStringNotContainsString('Male · 35 · MB-002', $html);
        $this->assertStringNotContainsString('Female · 35 · MB-002', $html);
        $this->assertStringContainsString('Cardiac arrest', $html);
        $this->assertStringContainsString('July 12, 2026', $html);
        $this->assertStringContainsString('2026-00123', $html);
        $this->assertStringContainsString('DC-2026-00451', $html);
        $this->assertStringContainsString('Cause of Death', $html);
        $this->assertStringContainsString('Date of Death', $html);
        $this->assertStringContainsString('Registry No.', $html);
        $this->assertStringContainsString('Certificate No.', $html);
        $this->assertStringContainsString('Submitted By', $html);
        $this->assertStringContainsString('Submitted On', $html);
        $this->assertStringContainsString('Death Certificate', $html);
        $this->assertStringContainsString('certificate.pdf', $html);
        $this->assertStringContainsString('Approve', $html);
        $this->assertStringContainsString('Reject', $html);
    }

    public function test_unknown_request_id_is_not_found(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get('/death-requests/99999')
            ->assertNotFound();
    }

    public function test_admin_can_approve_and_resident_becomes_deceased(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();
        $household = DemoCatalog::findHousehold('HH-151');
        $this->assertNotNull($household);
        $this->assertSame(6, $household['members']);

        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->post(route('death-requests.approve', $request))
            ->assertRedirect(route('death-requests.show', $request));

        $request->refresh();
        $this->assertTrue($request->isApproved());
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('admin', $request->reviewed_by_role);
        $this->assertTrue(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
        $this->assertSame(ResidentVitalStatus::DECEASED, ResidentVitalStatus::label('HH-151', 'MB-002'));

        $status = ResidentStatus::forMember('HH-151', 'MB-002');
        $this->assertNotNull($status);
        $this->assertTrue($status->isDeceased());
        $this->assertSame($request->id, $status->death_request_id);

        $stillThere = DemoCatalog::findHousehold('HH-151');
        $this->assertNotNull($stillThere);
        $this->assertSame(6, $stillThere['members']);
        $this->assertNotNull(lml_demo_find_member($stillThere, 'MB-002'));
        $this->assertTrue(DeathRequest::query()->whereKey($request->id)->exists());

        $page = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));
        $page->assertOk();
        $page->assertSee('Deceased', false);
        $page->assertSee('Historical health records are retained', false);
    }

    public function test_rejection_requires_reason_and_leaves_resident_active(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [])
            ->assertRedirect()
            ->assertSessionHasErrors('rejection_reason');

        $request->refresh();
        $this->assertTrue($request->isPending());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));

        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Certificate number does not match the uploaded file.',
            ])
            ->assertRedirect(route('death-requests.show', $request));

        $request->refresh();
        $this->assertTrue($request->isRejected());
        $this->assertSame('Certificate number does not match the uploaded file.', $request->rejection_reason);
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
        $this->assertNull(ResidentStatus::forMember('HH-151', 'MB-002'));

        $bhw = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));
        $bhw->assertOk();
        $bhw->assertSee('Rejected', false);
        $bhw->assertSee('Certificate number does not match the uploaded file.', false);
        $bhw->assertSee('name="cause_of_death"', false);
    }

    public function test_admin_can_download_certificate(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('death-requests.certificate', $request))
            ->assertOk();
    }

    private function submitPending(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();
    }
}
