<?php

namespace Tests\Feature;

use App\Support\StaffRole;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentStatus;
use App\Support\DeathCertificateStorage;
use App\Support\DeathRequestRegistryBackfill;
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
        $this->actingAsStaff(StaffRole::BHW);
        $missing = $this->get('/health-records/death/HH-999/MB-001');

        $missing->assertOk();
        $missing->assertSee('Resident not found', false);

        $this->seedPersistedKristine();
        $this->actingAsStaff(StaffRole::BHW);
        $found = $this->get(route('health-records.death.show', [
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
        $this->assertStringContainsString('Registry Number', $html);
        $this->assertStringContainsString('name="registry_no"', $html);
        $this->assertStringNotContainsString('Certificate No.', $html);
        $this->assertStringNotContainsString('name="certificate_no"', $html);
        $this->assertStringContainsString('name="death_certificate"', $html);
        $this->assertStringNotContainsString('Death Certificate No.', $html);
        $this->assertStringContainsString('Submit for Verification', $html);
        $this->assertMatchesRegularExpression('/data-death-submit[^>]*\bdisabled\b/u', $html);
        $this->assertStringContainsString('role="dialog"', $html);
    }

    public function test_server_rejects_incomplete_submissions(): void
    {
        $this->seedPersistedKristine();

        $url = route('health-records.death.store', [
            'householdNo' => 'HH-151',
            'memberId' => 'MB-002',
        ]);

        $this->actingAsStaff(StaffRole::BHW);
        $this->from(route('health-records.death.show', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]))
            ->post($url, [])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'cause_of_death',
                'date_of_death',
                'registry_no',
                'death_certificate',
            ]);

        $this->assertSame(0, DeathRequest::query()->count());
        $this->assertFalse(ResidentVitalStatus::isDeceased('HH-151', 'MB-002'));
    }

    public function test_successful_submission_creates_pending_request_without_marking_deceased(): void
    {
        $seed = $this->seedPersistedKristine();
        $file = UploadedFile::fake()->create('certificate_rosario_cruz.pdf', 120, 'application/pdf');

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
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
        $this->assertSame('2026-00123', $request->certificate_no);
        $this->assertSame('bhw', $request->submitted_by_role);
        $this->assertSame($seed['resident']->id, $request->resident_id);
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
        $html = $page->getContent();
        $page->assertSee('Pending Admin Verification', false);
        $page->assertSee('has not received final deceased status', false);
        $page->assertDontSee('name="cause_of_death"', false);
        $this->assertStringContainsString('Registry Number', $html);
        $this->assertStringContainsString('2026-00123', $html);
        $this->assertStringNotContainsString('Certificate No.', $html);
        $this->assertStringNotContainsString('Death Certificate No.', $html);
        $this->assertStringContainsString('Death Certificate', $html);
    }

    public function test_duplicate_pending_request_is_rejected(): void
    {
        $this->seedPersistedKristine();

        $payload = [
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => '2026-00123',
            'certificate_no' => 'DC-2026-00451',
            'death_certificate' => UploadedFile::fake()->create('a.pdf', 80, 'application/pdf'),
        ];

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
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

        $this->actingAsStaff(StaffRole::BHW);
        $this->get(route('death-requests.certificate', $request))
            ->assertForbidden();
    }

    public function test_staff_can_retrieve_persisted_certificate_for_the_resident(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::BHW);
        $response = $this->get(route('health-records.death.certificate', [
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

    public function test_canonical_registry_number_is_copied_to_legacy_certificate_no_column(): void
    {
        $this->seedPersistedKristine();
        $file = UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf');

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => '2026-00123',
                'death_certificate' => $file,
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame('2026-00123', $request->registry_no);
        $this->assertSame('2026-00123', $request->certificate_no);
        $this->assertSame($request->registry_no, $request->certificate_no);
    }

    public function test_posted_certificate_no_cannot_override_registry_number(): void
    {
        $this->seedPersistedKristine();
        $file = UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf');

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
                'householdNo' => 'HH-151',
                'memberId' => 'MB-002',
            ]), [
                'cause_of_death' => 'Cardiac arrest',
                'date_of_death' => '2026-07-12',
                'registry_no' => 'REG-2026-001',
                'certificate_no' => 'WRONG-999',
                'death_certificate' => $file,
            ])
            ->assertRedirect();

        $request = DeathRequest::query()->firstOrFail();
        $this->assertSame('REG-2026-001', $request->registry_no);
        $this->assertSame('REG-2026-001', $request->certificate_no);
        $this->assertSame('REG-2026-001', $request->displayRegistryNo());
        $this->assertNotSame('WRONG-999', $request->certificate_no);
    }

    public function test_d01_backfill_copies_historical_certificate_no_into_blank_registry_no(): void
    {
        $historical = DeathRequest::query()->create([
            'household_no' => 'HH-153',
            'member_id' => 'MB-005',
            'resident_name' => 'Adrian Corporal',
            'resident_sex' => 'Male',
            'resident_age' => 35,
            'zone' => 'Zone 1',
            'household_display_no' => 'HH 153',
            'address' => 'Layuan St., Brgy. La Medalla',
            'cause_of_death' => 'SILOS',
            'date_of_death' => '2026-08-03',
            'registry_no' => '',
            'certificate_no' => 'DC-OLD-001',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'HH-153/MB-005/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now()->subDay(),
        ]);

        $mirrored = DeathRequest::query()->create([
            'household_no' => 'HH-151',
            'member_id' => 'MB-001',
            'resident_name' => 'Kristine Reyes',
            'resident_sex' => 'Male',
            'resident_age' => 34,
            'zone' => 'Zone 2',
            'household_display_no' => 'HH 151',
            'address' => 'Sample Address',
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => 'REG-001',
            'certificate_no' => 'REG-001',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'HH-151/MB-001/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now()->subHours(2),
        ]);

        $protected = DeathRequest::query()->create([
            'household_no' => 'HH-152',
            'member_id' => 'MB-004',
            'resident_name' => 'Carlo Evangelista',
            'resident_sex' => 'Male',
            'resident_age' => 40,
            'zone' => 'Zone 5',
            'household_display_no' => 'HH 152',
            'address' => 'Sample Address',
            'cause_of_death' => 'Accident',
            'date_of_death' => '2026-06-01',
            'registry_no' => 'REG-CURRENT',
            'certificate_no' => 'OLD-CERT',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'HH-152/MB-004/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now()->subHours(3),
        ]);

        $this->assertSame(1, DeathRequestRegistryBackfill::run());

        $historical->refresh();
        $mirrored->refresh();
        $protected->refresh();

        $this->assertSame('DC-OLD-001', $historical->registry_no);
        $this->assertSame('DC-OLD-001', $historical->certificate_no);
        $this->assertSame('DC-OLD-001', $historical->displayRegistryNo());

        $this->assertSame('REG-001', $mirrored->registry_no);
        $this->assertSame('REG-001', $mirrored->certificate_no);

        $this->assertSame('REG-CURRENT', $protected->registry_no);
        $this->assertSame('OLD-CERT', $protected->certificate_no);
        $this->assertSame('REG-CURRENT', $protected->displayRegistryNo());

        $this->assertSame(0, DeathRequestRegistryBackfill::run());
    }

    public function test_d01_historical_registry_displays_on_admin_verify_and_death_record(): void
    {
        $request = DeathRequest::query()->create([
            'household_no' => 'HH-153',
            'member_id' => 'MB-005',
            'resident_name' => 'Adrian Corporal',
            'resident_sex' => 'Male',
            'resident_age' => 35,
            'zone' => 'Zone 1',
            'household_display_no' => 'HH 153',
            'address' => 'Layuan St., Brgy. La Medalla',
            'cause_of_death' => 'SILOS',
            'date_of_death' => '2026-08-03',
            'registry_no' => '',
            'certificate_no' => 'DC-OLD-001',
            'certificate_disk' => 'death_certificates',
            'certificate_path' => 'HH-153/MB-005/1/file.pdf',
            'certificate_original_name' => 'certificate.pdf',
            'certificate_mime' => 'application/pdf',
            'certificate_size' => 1200,
            'certificate_extension' => 'pdf',
            'status' => DeathRequest::STATUS_PENDING,
            'submitted_by_name' => 'Sarah',
            'submitted_by_role' => 'bhw',
            'submitted_at' => now()->subDay(),
        ]);

        DeathRequestRegistryBackfill::run();
        $request->refresh();

        Household::factory()->create([
            'household_no' => 'HH-153',
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
        ]);
        Resident::factory()->create([
            'household_id' => Household::query()->where('household_no', 'HH-153')->value('id'),
            'member_no' => 'MB-005',
            'first_name' => 'Adrian',
            'last_name' => 'Corporal',
            'relation' => 'Head',
            'sex' => 'Male',
        ]);

        $this->actingAsStaff(StaffRole::ADMIN);
        $adminHtml = $this->get(route('death-requests.show', $request))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Registry Number', $adminHtml);
        $this->assertStringNotContainsString('Certificate No.', $adminHtml);
        $this->assertStringContainsString('DC-OLD-001', $adminHtml);
        $this->assertStringNotContainsString('Death Certificate No.', $adminHtml);
        $this->assertStringNotContainsString('name="certificate_no"', $adminHtml);

        $this->actingAsStaff(StaffRole::BHW);
        $recordHtml = $this->get(route('health-records.death.show', [
                'householdNo' => 'HH-153',
                'memberId' => 'MB-005',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Registry Number', $recordHtml);
        $this->assertStringNotContainsString('Certificate No.', $recordHtml);
        $this->assertStringContainsString('DC-OLD-001', $recordHtml);
        $this->assertStringNotContainsString('Death Certificate No.', $recordHtml);
        $this->assertStringNotContainsString('name="certificate_no"', $recordHtml);
    }

    /**
     * @return array{household: Household, resident: Resident}
     */
    private function seedPersistedKristine(): array
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Wife',
            'sex' => 'Female',
            'birthday' => '1991-08-12',
            'relationship_status' => 'Married',
            'occupation' => 'Nurse',
        ]);

        return [
            'household' => $household,
            'resident' => $resident,
        ];
    }

    private function submitPending(): void
    {
        $this->seedPersistedKristine();

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('health-records.death.store', [
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
