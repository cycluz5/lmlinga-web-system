<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\AtRestEncrypter;
use App\Support\StaffRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AssertsAtRestStoredField;
use Tests\TestCase;

class DeathRequestRejectionSafetyTest extends TestCase
{
    use AssertsAtRestStoredField;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_admin_can_reject_pending_request_with_plaintext_reason(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Certificate number does not match the uploaded file.',
            ])
            ->assertRedirect(route('death-requests.show', $request))
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertTrue($request->isRejected());
        $this->assertSame(
            'Certificate number does not match the uploaded file.',
            $request->rejection_reason
        );
        $this->assertPlaintextStoredField(
            'death_requests',
            'rejection_reason',
            'Certificate number does not match the uploaded file.',
            ['id' => $request->id]
        );
        $this->assertSame('Cardiac arrest', (string) DB::table('death_requests')->value('cause_of_death'));
    }

    public function test_rejection_reason_is_plaintext_and_does_not_need_the_at_rest_key(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        config(['lmlinga.at_rest.key' => '', 'lmlinga.at_rest.enabled' => true]);
        $this->app->forgetInstance(AtRestEncrypter::class);

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Registry number is unreadable.',
            ])
            ->assertRedirect(route('death-requests.show', $request))
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertTrue($request->isRejected());
        $this->assertPlaintextStoredField(
            'death_requests',
            'rejection_reason',
            'Registry number is unreadable.',
            ['id' => $request->id]
        );
    }

    public function test_empty_rejection_reason_is_validated(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [])
            ->assertRedirect()
            ->assertSessionHasErrors('rejection_reason');

        $request->refresh();
        $this->assertTrue($request->isPending());
    }

    public function test_rejection_reason_longer_than_one_thousand_characters_is_rejected(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => str_repeat('a', 1001),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rejection_reason');

        $request->refresh();
        $this->assertTrue($request->isPending());
        $this->assertNull(DB::table('death_requests')->where('id', $request->id)->value('rejection_reason'));
    }

    public function test_verified_request_cannot_be_rejected(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $request))->assertRedirect();

        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Should not apply after approval.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->get(route('death-requests.show', $request))
            ->assertOk()
            ->assertSee('data-death-status-error', false)
            ->assertSee('Only pending death requests can be rejected.', false);

        $request->refresh();
        $this->assertTrue($request->isApproved());
        $this->assertNull(DB::table('death_requests')->where('id', $request->id)->value('rejection_reason'));
    }

    public function test_rejected_request_cannot_be_rejected_again(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.reject', $request), [
            'rejection_reason' => 'First rejection.',
        ])->assertRedirect();

        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Second rejection must not apply.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $request->refresh();
        $this->assertTrue($request->isRejected());
        $this->assertSame('First rejection.', $request->rejection_reason);
        $this->assertPlaintextStoredField(
            'death_requests',
            'rejection_reason',
            'First rejection.',
            ['id' => $request->id]
        );
    }

    public function test_worker_cannot_reject_and_guest_is_unauthenticated(): void
    {
        $this->submitPending();
        $id = (int) DeathRequest::query()->value('id');

        $this->actingAsStaff(StaffRole::BHW);
        $this->post(route('death-requests.reject', $id), [
            'rejection_reason' => 'Should not be allowed.',
        ])->assertForbidden();

        $this->assertTrue(DeathRequest::query()->findOrFail($id)->isPending());

        auth()->logout();
        $this->assertGuest();
        $this->post(route('death-requests.reject', $id), [
            'rejection_reason' => 'Guest must not reject.',
        ])->assertRedirect(route('login'));

        $this->assertTrue(DeathRequest::query()->findOrFail($id)->isPending());
    }

    public function test_approve_still_succeeds_after_rejection_safety_mapping(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $request))
            ->assertRedirect(route('death-requests.show', $request));

        $request->refresh();
        $this->assertTrue($request->isApproved());
    }

    public function test_posted_status_and_resident_fields_cannot_force_rejection(): void
    {
        $this->submitPending();
        $request = DeathRequest::query()->firstOrFail();
        $residentId = (int) $request->resident_id;

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $request))->assertRedirect();

        $this->from(route('death-requests.show', $request))
            ->post(route('death-requests.reject', $request), [
                'rejection_reason' => 'Crafted payload.',
                'status' => 'pending',
                'resident_id' => $residentId + 99,
                'verified_by' => 1,
                'submitted_by' => 1,
            ])
            ->assertSessionHasErrors('status');

        $request->refresh();
        $this->assertTrue($request->isApproved());
        $this->assertSame($residentId, (int) $request->resident_id);
    }

    private function submitPending(): void
    {
        $household = Household::factory()->create([
            'household_no' => 'HH-151',
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-002',
            'last_name' => 'Reyes',
            'first_name' => 'Kristine',
            'middle_name' => null,
            'relation' => 'Spouse',
            'sex' => 'Female',
            'birthday' => '1991-08-12',
            'relationship_status' => 'Married',
            'occupation' => 'Nurse',
        ]);

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
        ])->assertRedirect();
    }
}
