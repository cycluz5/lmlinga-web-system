<?php

namespace Tests\Feature;

use App\Models\DeathRequest;
use App\Models\Household;
use App\Models\Resident;
use App\Support\PendingDeathRequestCount;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeathRequestPendingBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('death_certificates');
    }

    public function test_admin_sidebar_shows_pending_death_request_count(): void
    {
        $this->submitPending();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, PendingDeathRequestCount::forAdminNav());
        $this->assertStringContainsString('lml-sidebar__count', $html);
        $this->assertMatchesRegularExpression(
            '/lml-sidebar__count"[^>]*>1<\/span>/u',
            $html
        );
        $this->assertStringContainsString('lml-sidebar__attention-dot', $html);
        $this->assertStringContainsString('1 pending death request', $html);
        $this->assertStringContainsString('href="'.e(route('death-requests.index')).'"', $html);
    }

    public function test_admin_sidebar_omits_badge_when_no_pending_requests(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(0, PendingDeathRequestCount::forAdminNav());
        $this->assertStringNotContainsString('lml-sidebar__count', $html);
        $this->assertStringNotContainsString('lml-sidebar__attention-dot', $html);
        $this->assertStringNotContainsString('pending death request', $html);
    }

    public function test_verified_and_rejected_requests_are_not_counted(): void
    {
        $this->submitPending();
        $first = DeathRequest::query()->firstOrFail();

        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.approve', $first))->assertRedirect();

        $this->assertSame(0, DeathRequest::query()->pending()->count());

        $this->submitPending([
            'household_no' => 'HH-152',
            'member_no' => 'MB-003',
            'registry_no' => '2026-00124',
        ]);
        $second = DeathRequest::query()->pending()->firstOrFail();
        $this->actingAsStaff(StaffRole::ADMIN);
        $this->post(route('death-requests.reject', $second), [
            'rejection_reason' => 'Certificate number does not match the uploaded file.',
        ])->assertRedirect();

        $this->actingAsStaff(StaffRole::ADMIN);
        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(0, PendingDeathRequestCount::forAdminNav());
        $this->assertSame(0, DeathRequest::query()->pending()->count());
        $this->assertStringNotContainsString('lml-sidebar__count', $html);
        $this->assertStringNotContainsString('lml-sidebar__attention-dot', $html);
    }

    public function test_workers_do_not_receive_pending_count_in_navigation(): void
    {
        $this->submitPending();

        foreach ([StaffRole::BHW, StaffRole::BNS, StaffRole::BSPO] as $role) {
            $this->actingAsStaff($role);
            $this->assertFalse(UiRole::isAdmin());
            $this->assertSame(0, PendingDeathRequestCount::forAdminNav());

            $html = $this->get(route('dashboard'))->assertOk()->getContent();
            $this->assertStringNotContainsString('lml-sidebar__count', $html);
            $this->assertStringNotContainsString('lml-sidebar__attention-dot', $html);
            $this->assertStringNotContainsString('pending death request', $html);
            $this->assertStringNotContainsString('href="'.e(route('death-requests.index')).'"', $html);
        }
    }

    public function test_missing_death_tables_do_not_fail_unrelated_pages(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);
        \Illuminate\Support\Facades\Schema::dropIfExists('death_requests');
        \Illuminate\Support\Facades\Schema::dropIfExists('death_records');
        \App\Support\DeathRecordsErdMode::resetCachedState();

        $this->assertSame(0, PendingDeathRequestCount::safePendingCount());
        $this->get(route('dashboard'))->assertOk();
    }

    /**
     * @param  array{household_no?: string, member_no?: string, registry_no?: string}  $overrides
     */
    private function submitPending(array $overrides = []): void
    {
        $householdNo = $overrides['household_no'] ?? 'HH-151';
        $memberNo = $overrides['member_no'] ?? 'MB-002';
        $registryNo = $overrides['registry_no'] ?? '2026-00123';

        $household = Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 2',
            'street' => 'Layuan St.',
            'address' => 'Layuan St., Brgy. La Medalla',
        ]);

        Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => $memberNo,
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
            'householdNo' => $householdNo,
            'memberId' => $memberNo,
        ]), [
            'cause_of_death' => 'Cardiac arrest',
            'date_of_death' => '2026-07-12',
            'registry_no' => $registryNo,
            'certificate_no' => $registryNo,
            'death_certificate' => UploadedFile::fake()->create('certificate.pdf', 120, 'application/pdf'),
        ])->assertRedirect();
    }
}
