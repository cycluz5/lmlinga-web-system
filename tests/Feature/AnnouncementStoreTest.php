<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\User;
use App\Services\AnnouncementNotificationService;
use App\Support\DemoStaffLogin;
use App\Support\StaffRole;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnnouncementStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Free Deworming Program — August 30',
            'message' => 'Bring your children for deworming.',
            'date' => '2026-08-30',
            'time' => '08:00',
            'place' => 'Barangay Health Center',
            'audience_type' => 'all',
            'zone_coverage' => 'all',
        ], $overrides);
    }

    private function actingAsStaffRole(string $role): static
    {
        $this->actingAsStaff($role);

        return $this->withSession([
            UiRole::SESSION_KEY => $role,
            DemoStaffLogin::SESSION_DISPLAY_NAME => strtoupper($role).' Staff',
        ]);
    }

    public function test_supported_roles_can_post(): void
    {
        foreach (UiRole::ALLOWED as $role) {
            $this->actingAsStaffRole($role)
                ->post(route('announcements.store'), $this->validPayload([
                    'title' => "Announcement for {$role}",
                ]))
                ->assertRedirect(route('announcements.index'))
                ->assertSessionHas('status');

            $this->assertDatabaseHas('announcements', [
                'title' => "Announcement for {$role}",
                'posted_by_role' => $role,
            ]);
        }
    }

    public function test_unsupported_role_cannot_post(): void
    {
        $this->withSession([])
            ->post(route('announcements.store'), $this->validPayload())
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_valid_announcement_persists_core_fields(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload())
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'title' => 'Free Deworming Program — August 30',
            'message' => 'Bring your children for deworming.',
            'place' => 'Barangay Health Center',
            'target_group' => 'all',
            'zone_mode' => 'all',
        ]);

        $announcement = Announcement::query()->first();
        $this->assertNotNull($announcement);
        $this->assertSame('2026-08-30', $announcement->event_date->toDateString());
    }

    public function test_past_event_date_is_rejected(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->from(route('announcements.create'))
            ->post(route('announcements.store'), $this->validPayload([
                'date' => '2026-08-27',
            ]))
            ->assertRedirect(route('announcements.create'))
            ->assertSessionHasErrors('date');

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_today_event_date_is_accepted(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->post(route('announcements.store'), $this->validPayload([
            'date' => '2026-08-28',
        ]))
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->latest('id')->first();
        $this->assertNotNull($announcement);
        $this->assertSame('2026-08-28', $announcement->event_date->toDateString());
    }

    public function test_future_event_date_is_accepted(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $this->post(route('announcements.store'), $this->validPayload([
            'date' => '2026-08-29',
        ]))
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->latest('id')->first();
        $this->assertNotNull($announcement);
        $this->assertSame('2026-08-29', $announcement->event_date->toDateString());
    }

    public function test_create_form_sets_date_min_to_today(): void
    {
        $this->actingAsStaff(StaffRole::ADMIN);

        $html = $this->get(route('announcements.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('min="2026-08-28"', $html);
    }

    public function test_authenticated_staff_session_persists_posted_by_user_id(): void
    {
        $this->actingAsStaffRole('bhw')
            ->post(route('announcements.store'), $this->validPayload())
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->first();
        $this->assertNotNull($announcement);
        $this->assertNotNull($announcement->posted_by_user_id);
        $this->assertSame(Auth::id(), $announcement->posted_by_user_id);
        $this->assertSame('bhw', $announcement->posted_by_role);
    }

    public function test_database_auth_user_id_persists_when_available(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'email' => 'maria.santos@example.test',
        ]);
        $user->assignCurrentAppointment([
            'role' => StaffRole::ADMIN,
            'assigned_barangay' => 'La Medalla',
            'assigned_zone' => 'Zone 1',
            'date_appointed' => '2020-01-01',
        ]);

        Auth::login($user);
        $user->syncUiRoleSession();

        $this->post(route('announcements.store'), $this->validPayload())
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'posted_by_user_id' => $user->id,
            'posted_by_name' => $user->composeDisplayName(),
            'posted_by_role' => 'admin',
        ]);
    }

    public function test_age_target_persists_presets_and_normalized_months(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'audience_type' => 'age',
                'age_groups' => ['infants_0_6', 'young_children'],
                'age_from' => '2',
                'age_from_unit' => 'years',
                'age_to' => '4',
                'age_to_unit' => 'years',
            ]))
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->first();
        $this->assertNotNull($announcement);
        $this->assertSame('age', $announcement->target_group);
        $this->assertSame(['infants_0_6', 'young_children'], $announcement->age_presets);
        $this->assertSame(24, $announcement->age_min_months);
        $this->assertSame(48, $announcement->age_max_months);
        $this->assertStringContainsString('Infants 0–6 months', $announcement->audience_label);
        $this->assertStringContainsString('Young Children 1–5 years', $announcement->audience_label);
    }

    public function test_active_maternal_and_fp_targets_persist(): void
    {
        foreach ([
            'active_maternal' => 'Active Maternal',
            'active_fp_user' => 'Active FP User',
        ] as $target => $label) {
            Announcement::query()->delete();

            $this->actingAsStaffRole('bns')
                ->post(route('announcements.store'), $this->validPayload([
                    'audience_type' => $target,
                ]))
                ->assertRedirect(route('announcements.index'));

            $this->assertDatabaseHas('announcements', [
                'target_group' => $target,
                'audience_label' => $label,
            ]);
        }
    }

    public function test_specific_zones_persist_normalized_values_including_zone_five(): void
    {
        $this->actingAsStaffRole('bspo')
            ->post(route('announcements.store'), $this->validPayload([
                'zone_coverage' => 'specific',
                'zones' => ['1', '5'],
                'custom_zones' => ['  North Purok '],
            ]))
            ->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->first();
        $this->assertNotNull($announcement);
        $this->assertSame('specific', $announcement->zone_mode);
        $this->assertEqualsCanonicalizing(
            ['Zone 1', 'Zone 5', 'North Purok'],
            $announcement->zones,
        );
    }

    public function test_server_recomputes_estimated_reach_for_active_maternal(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 2']);
        $active = Resident::factory()->create([
            'household_id' => $household->id,
            'birthday' => '1995-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'birthday' => '1997-01-01',
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $active->id,
            'status' => MaternalPregnancy::STATUS_ACTIVE,
        ]);

        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'audience_type' => 'active_maternal',
                'zone_coverage' => 'specific',
                'zones' => ['2'],
            ]))
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'target_group' => 'active_maternal',
            'estimated_reach' => 1,
        ]);
    }

    public function test_server_recomputes_estimated_reach_for_active_fp_user(): void
    {
        $household = Household::factory()->create(['zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $household->id,
            'birthday' => '1990-01-01',
            'fp_user' => 'Yes',
        ]);
        Resident::factory()->create([
            'household_id' => $household->id,
            'birthday' => '1991-01-01',
            'fp_user' => 'No',
        ]);

        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'audience_type' => 'active_fp_user',
            ]))
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseHas('announcements', [
            'target_group' => 'active_fp_user',
            'estimated_reach' => 1,
        ]);
    }

    public function test_client_cannot_spoof_estimated_reach_or_audience_label(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'estimated_reach' => 99999,
                'audience_label' => 'Pregnant',
            ]))
            ->assertSessionHasErrors(['estimated_reach', 'audience_label']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_invalid_target_group_is_rejected(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'audience_type' => 'condition',
            ]))
            ->assertSessionHasErrors(['audience_type']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_invalid_age_target_without_selection_is_rejected(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'audience_type' => 'age',
            ]))
            ->assertSessionHasErrors(['age_groups']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_invalid_specific_zone_selection_is_rejected(): void
    {
        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'zone_coverage' => 'specific',
            ]))
            ->assertSessionHasErrors(['zones']);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_index_reads_persisted_announcements(): void
    {
        Announcement::factory()->create([
            'title' => 'Persisted Index Announcement',
            'audience_label' => 'All Residents',
            'event_date' => now()->addDay()->toDateString(),
            'posted_at' => now(),
        ]);

        $this->actingAsStaffRole('admin')
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Persisted Index Announcement', false);
    }

    public function test_upcoming_reads_persisted_announcements(): void
    {
        Announcement::factory()->create([
            'title' => 'Persisted Upcoming Announcement',
            'event_date' => now()->addDays(3)->toDateString(),
            'posted_at' => now(),
        ]);

        $this->actingAsStaffRole('admin')
            ->get(route('announcements.upcoming'))
            ->assertOk()
            ->assertSee('Persisted Upcoming Announcement', false);
    }

    public function test_recent_reads_persisted_announcements(): void
    {
        Announcement::factory()->create([
            'title' => 'Persisted Recent Announcement',
            'event_date' => now()->subDay()->toDateString(),
            'posted_at' => now(),
        ]);

        $this->actingAsStaffRole('admin')
            ->get(route('announcements.recent'))
            ->assertOk()
            ->assertSee('Persisted Recent Announcement', false);
    }

    public function test_store_fans_out_notifications_to_linked_recipient_accounts(): void
    {
        $this->ensureNotificationsTable();

        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-STORE-1']);
        $linked = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1990-01-01']);
        Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1991-01-01']);

        $account = ResidentAccount::factory()->linkedTo($linked)->create([
            'email' => 'store.linked@example.test',
        ]);
        ResidentAccount::factory()->create([
            'resident_id' => null,
            'email' => 'store.unlinked@example.test',
        ]);

        $this->actingAsStaffRole('admin')
            ->post(route('announcements.store'), $this->validPayload([
                'title' => 'Store Fan-Out Notice',
                'message' => 'Linked accounts only.',
                'audience_type' => 'all',
                'zone_coverage' => 'all',
            ]))
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseHas('announcements', [
            'title' => 'Store Fan-Out Notice',
            'message' => 'Linked accounts only.',
        ]);

        $this->assertSame(1, DB::table('notifications')->count());
        $row = (array) DB::table('notifications')->first();
        $this->assertSame($account->getKey(), $row['account_id']);
        $this->assertSame(AnnouncementNotificationService::NOTIFICATION_TYPE_SYSTEM, $row['notification_type']);
        $this->assertSame('Store Fan-Out Notice', $row['title']);
        $this->assertSame('Linked accounts only.', $row['message']);
        $this->assertSame(0, (int) $row['is_read']);
        $this->assertNull($row['related_request_id']);
        $this->assertNull($row['related_conversation_id']);
    }

    public function test_update_does_not_fan_out_notifications(): void
    {
        $this->ensureNotificationsTable();

        $hh = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-STORE-2']);
        $resident = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1988-01-01']);
        ResidentAccount::factory()->linkedTo($resident)->create([
            'email' => 'update.nofanout@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'title' => 'Before Update',
            'message' => 'Original body.',
            'event_date' => '2026-09-05',
            'target_group' => Announcement::TARGET_ALL,
            'zone_mode' => Announcement::ZONE_ALL,
            'posted_at' => now(),
        ]);

        $this->assertSame(0, DB::table('notifications')->count());

        $this->actingAsStaffRole('admin')
            ->put(route('announcements.update', $announcement), $this->validPayload([
                'title' => 'After Update',
                'message' => 'Updated body.',
                'date' => '2026-09-10',
                'audience_type' => 'all',
                'zone_coverage' => 'all',
            ]))
            ->assertRedirect(route('announcements.index'));

        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'title' => 'After Update',
        ]);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    private function ensureNotificationsTable(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->id('notification_id');
            $table->unsignedBigInteger('account_id');
            $table->string('notification_type', 64);
            $table->string('title', 150);
            $table->text('message')->nullable();
            $table->unsignedBigInteger('related_request_id')->nullable();
            $table->unsignedBigInteger('related_conversation_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }
}
