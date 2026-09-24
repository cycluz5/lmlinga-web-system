<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\ResidentStatus;
use App\Support\DeathCertificateStorage;
use App\Support\ResidentVitalStatus;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeathRecordSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_death_form_requires_selected_resident_context(): void
    {
        $missing = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get('/health-records/death/HH-999/MB-001');

        $missing->assertOk();
        $missing->assertSee('Resident not found', false);

        $found = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));

        $found->assertOk();
        $html = $found->getContent();
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('Member ID', $html);
        $this->assertStringContainsString('MB-002', $html);
        $this->assertStringContainsString('Wife', $html);
        $this->assertStringContainsString('name="cause_of_death"', $html);
        $this->assertStringContainsString('Death Certificate No.', $html);
        $this->assertStringContainsString('Registry No.', $html);
        $this->assertStringContainsString('name="registry_no"', $html);
        $this->assertStringContainsString('Submit for Verification', $html);
        $this->assertMatchesRegularExpression('/data-death-submit[^>]*\bdisabled\b/u', $html);
        $this->assertStringContainsString('role="dialog"', $html);
    }

    public function test_server_rejects_incomplete_submissions(): void
    {
        $url = route('health-records.death.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ]);

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->from(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->post($url, [])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'cause_of_death',
                'date_of_death',
                'registry_no',
                'certificate_no',
                'death_certificate',
            ]);

        $this->assertSame(0, DeathRequest::query()->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
    }

    public function test_successful_submission_creates_pending_request_without_marking_deceased(): void
    {
        $file = UploadedFile::fake()->create('certificate_rosario_cruz.pdf', 120, 'application/pdf');

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => $file,
            ])
            ->assertRedirect(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));

        $request = DeathRequest::query()->first();
        $this->assertNotNull($request);
        $this->assertSame(DeathRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Cardiac arrest', $request->cause_of_death);
        $this->assertSame('2026-00123', $request->registry_no);
        $this->assertSame('DC-2026-00451', $request->certificate_no);
        $this->assertSame('bhw', $request->submitted_by_role);
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
        $this->assertNull(ResidentStatus::forMember('HH-151', 'MB-002'));
        $this->assertSame(0, ResidentStatus::query()->count());
        $this->assertTrue(DeathCertificateStorage::exists($request));
        Storage::disk('death_certificates')->assertExists($request->certificate_path);

        $page = $this->get(route('health-records.death.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ]));
        $page->assertOk();
        $page->assertSee('Pending Admin Verification', false);
        $page->assertSee('has not received final deceased status', false);
        $page->assertDontSee('name="cause_of_death"', false);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $payload = [
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => '2026-00123',
            'certificate_no' => 'DC-2026-00451',
            'death_certificate' => UploadedFile::fake()->create('a.pdf', 80, 'application/pdf'),
        ];

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), $payload)
            ->assertRedirect();

        $this->from(route('health-records.death.show', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ]))
            ->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                ...$payload,
                'death_certificate' => UploadedFile::fake()->create('b.pdf', 80, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('cause_of_death');

        $this->assertSame(1, DeathRequest::query()->count());
    }

    public function test_unauthorized_user_cannot_download_certificate_from_admin_route(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('death-requests.certificate', $request))
            ->assertForbidden();
    }

    public function test_staff_can_retrieve_persisted_certificate_for_the_resident(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $response = $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('health-records.death.certificate', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringNotContainsString(
            (string) $request->certificate_path,
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_registry_no_and_certificate_no_persist_independently(): void
    {
        $file = UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf');

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'certificate_no' => 'DC-2026-00451',
                'death_certificate' => $file,
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame('2026-00123', $request->registry_no);
        $this->assertSame('DC-2026-00451', $request->certificate_no);
        $this->assertNotSame($request->registry_no, $request->certificate_no);
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
