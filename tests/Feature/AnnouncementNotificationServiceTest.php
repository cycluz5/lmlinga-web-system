<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\AnnouncementNotificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Recipient resolution + fanOut insert — not wired to AnnouncementStoreService.
 */
class AnnouncementNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnnouncementNotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureNotificationsTable();
        $this->service = $this->app->make(AnnouncementNotificationService::class);
    }

    public function test_resolves_only_linked_account_ids_for_matched_residents(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-N1']);
        $linked = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1990-01-01']);
        $unlinked = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1991-01-01']);

        $account = ResidentAccount::factory()->linkedTo($linked)->create([
            'email' => 'linked.announce@example.test',
        ]);
        ResidentAccount::factory()->create([
            'resident_id' => null,
            'email' => 'unlinked.portal@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_ALL,
            'zone_mode' => Announcement::ZONE_ALL,
            'zones' => null,
            'age_presets' => null,
            'age_min_months' => null,
            'age_max_months' => null,
        ]);

        $ids = $this->service->recipientAccountIds($announcement);

        $this->assertSame([$account->getKey()], $ids->all());
        $this->assertSame(1, $this->service->notifyableCount($announcement));
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertNotNull($unlinked->getKey());
    }

    public function test_empty_audience_returns_empty_collection(): void
    {
        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_ACTIVE_MATERNAL,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $ids = $this->service->recipientAccountIds($announcement);

        $this->assertSame([], $ids->all());
        $this->assertSame(0, $this->service->notifyableCount($announcement));
    }

    public function test_returns_distinct_account_ids(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-N2']);
        $r1 = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1985-01-01']);
        $r2 = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1986-01-01']);

        $a1 = ResidentAccount::factory()->linkedTo($r1)->create(['email' => 'a1@example.test']);
        $a2 = ResidentAccount::factory()->linkedTo($r2)->create(['email' => 'a2@example.test']);

        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_ALL,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $ids = $this->service->recipientAccountIds($announcement);

        $this->assertEqualsCanonicalizing([$a1->getKey(), $a2->getKey()], $ids->all());
        $this->assertSame($ids->count(), $ids->unique()->count());
    }

    public function test_child_match_resolves_parent_household_account(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-CHILD-1']);
        $parent = Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'birthday' => '1990-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'birthday' => now()->subMonths(3)->toDateString(),
        ]);

        $parentAccount = ResidentAccount::factory()->linkedTo($parent)->create([
            'email' => 'parent.childmatch@example.test',
        ]);

        $otherHh = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-OTHER-1']);
        $otherParent = Resident::factory()->create([
            'household_id' => $otherHh->getKey(),
            'birthday' => '1988-01-01',
        ]);
        ResidentAccount::factory()->linkedTo($otherParent)->create([
            'email' => 'other.household@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_AGE,
            'age_presets' => ['infants_0_6'],
            'age_min_months' => null,
            'age_max_months' => null,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $ids = $this->service->recipientAccountIds($announcement);

        $this->assertSame([$parentAccount->getKey()], $ids->all());
    }

    public function test_multiple_matching_children_return_parent_account_once(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-CHILD-2']);
        $parent = Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'birthday' => '1985-06-15',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'birthday' => now()->subMonths(2)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'birthday' => now()->subMonths(5)->toDateString(),
        ]);

        $parentAccount = ResidentAccount::factory()->linkedTo($parent)->create([
            'email' => 'parent.twokids@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_AGE,
            'age_presets' => ['infants_0_6'],
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $ids = $this->service->recipientAccountIds($announcement);

        $this->assertSame([$parentAccount->getKey()], $ids->all());
        $this->assertSame(1, $ids->count());
    }

    public function test_fan_out_inserts_system_rows_for_linked_recipients_only(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-FO1']);
        $linked = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1990-01-01']);
        Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1991-01-01']);

        $account = ResidentAccount::factory()->linkedTo($linked)->create([
            'email' => 'fanout.linked@example.test',
        ]);
        ResidentAccount::factory()->create([
            'resident_id' => null,
            'email' => 'fanout.unlinked@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'title' => 'Barangay Health Day',
            'message' => 'Free BP screening this Saturday.',
            'target_group' => Announcement::TARGET_ALL,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $created = $this->service->fanOut($announcement);

        $this->assertSame(1, $created);
        $this->assertSame(1, DB::table('notifications')->count());

        $row = (array) DB::table('notifications')->first();
        $this->assertSame($account->getKey(), $row['account_id']);
        $this->assertSame(AnnouncementNotificationService::NOTIFICATION_TYPE_SYSTEM, $row['notification_type']);
        $this->assertSame('Barangay Health Day', $row['title']);
        $this->assertSame('Free BP screening this Saturday.', $row['message']);
        $this->assertSame(0, (int) $row['is_read']);
        $this->assertNull($row['related_request_id']);
        $this->assertNull($row['related_conversation_id']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function test_fan_out_with_no_recipients_inserts_nothing_and_returns_zero(): void
    {
        $announcement = Announcement::factory()->create([
            'target_group' => Announcement::TARGET_ACTIVE_MATERNAL,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $created = $this->service->fanOut($announcement);

        $this->assertSame(0, $created);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_fan_out_return_count_matches_inserted_rows(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 3', 'household_no' => 'HH-FO2']);
        $r1 = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1980-01-01']);
        $r2 = Resident::factory()->create(['household_id' => $hh->getKey(), 'birthday' => '1981-01-01']);

        ResidentAccount::factory()->linkedTo($r1)->create(['email' => 'count1@example.test']);
        ResidentAccount::factory()->linkedTo($r2)->create(['email' => 'count2@example.test']);

        $announcement = Announcement::factory()->create([
            'title' => 'Two Recipients',
            'message' => 'Both linked accounts.',
            'target_group' => Announcement::TARGET_ALL,
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $created = $this->service->fanOut($announcement);

        $this->assertSame(2, $created);
        $this->assertSame($created, DB::table('notifications')->count());
    }

    public function test_fan_out_persists_child_name_for_parent_account(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-CTX-1']);
        $parent = Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'P',
            'last_name' => 'Parent',
            'birthday' => '1990-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'first_name' => 'Ben',
            'middle_name' => 'C',
            'last_name' => 'Child',
            'birthday' => now()->subMonths(3)->toDateString(),
        ]);

        $parentAccount = ResidentAccount::factory()->linkedTo($parent)->create([
            'email' => 'parent.context@example.test',
        ]);

        $otherHh = Household::factory()->create(['zone' => 'Zone 2', 'household_no' => 'HH-CTX-OTHER']);
        $otherInfant = Resident::factory()->create([
            'household_id' => $otherHh->getKey(),
            'first_name' => 'Zoe',
            'middle_name' => 'X',
            'last_name' => 'Outsider',
            'birthday' => now()->subMonths(2)->toDateString(),
        ]);
        ResidentAccount::factory()->linkedTo($otherInfant)->create([
            'email' => 'outsider.context@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'title' => 'Infant Checkup',
            'message' => 'Bring vaccination card.',
            'place' => 'Barangay Health Center',
            'event_date' => '2026-10-01',
            'event_time' => '09:30:00',
            'target_group' => Announcement::TARGET_AGE,
            'age_presets' => ['infants_0_6'],
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $created = $this->service->fanOut($announcement);

        $this->assertSame(2, $created);

        $parentRow = (array) DB::table('notifications')
            ->where('account_id', $parentAccount->getKey())
            ->first();

        $this->assertSame('Ben C Child', $parentRow['recipient_context']);
        $this->assertStringNotContainsString('Ana', (string) $parentRow['recipient_context']);
        $this->assertStringNotContainsString('Outsider', (string) $parentRow['recipient_context']);
        $this->assertSame('Barangay Health Center', $parentRow['place']);
        $this->assertSame('2026-10-01', $parentRow['event_date']);
        $this->assertTrue(
            str_starts_with((string) $parentRow['event_time'], '09:30'),
            'Expected event_time to start with 09:30, got: '.$parentRow['event_time']
        );
    }

    public function test_fan_out_persists_multiple_matched_member_names_once(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1', 'household_no' => 'HH-CTX-2']);
        $parent = Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'first_name' => 'Parent',
            'middle_name' => 'A',
            'last_name' => 'Reyes',
            'birthday' => '1985-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'first_name' => 'Maria',
            'middle_name' => '',
            'last_name' => 'Cruz',
            'birthday' => now()->subMonths(2)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $hh->getKey(),
            'first_name' => 'Juan',
            'middle_name' => '',
            'last_name' => 'Cruz',
            'birthday' => now()->subMonths(5)->toDateString(),
        ]);

        $parentAccount = ResidentAccount::factory()->linkedTo($parent)->create([
            'email' => 'parent.multikids@example.test',
        ]);

        $announcement = Announcement::factory()->create([
            'title' => 'Twins Notice',
            'message' => 'Both infants.',
            'place' => 'Gym Hall',
            'event_date' => '2026-11-15',
            'event_time' => '14:00',
            'target_group' => Announcement::TARGET_AGE,
            'age_presets' => ['infants_0_6'],
            'zone_mode' => Announcement::ZONE_ALL,
        ]);

        $this->assertSame(1, $this->service->fanOut($announcement));

        $row = (array) DB::table('notifications')
            ->where('account_id', $parentAccount->getKey())
            ->first();

        $this->assertSame('Juan Cruz, Maria Cruz', $row['recipient_context']);
        $this->assertSame('Gym Hall', $row['place']);
        $this->assertSame('2026-11-15', $row['event_date']);
        $this->assertTrue(str_starts_with((string) $row['event_time'], '14:00'));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    private function ensureNotificationsTable(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->id('notification_id');
                $table->unsignedBigInteger('account_id');
                $table->string('notification_type', 64);
                $table->string('title', 150);
                $table->text('message')->nullable();
                $table->text('recipient_context')->nullable();
                $table->string('place', 120)->nullable();
                $table->date('event_date')->nullable();
                $table->time('event_time')->nullable();
                $table->unsignedBigInteger('related_request_id')->nullable();
                $table->unsignedBigInteger('related_conversation_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->useCurrent();
            });

            return;
        }

        if (! Schema::hasColumn('notifications', 'recipient_context')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->text('recipient_context')->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'place')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->string('place', 120)->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'event_date')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->date('event_date')->nullable();
            });
        }
        if (! Schema::hasColumn('notifications', 'event_time')) {
            Schema::table('notifications', function (Blueprint $table): void {
                $table->time('event_time')->nullable();
            });
        }
    }
}
