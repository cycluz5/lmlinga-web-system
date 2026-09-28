<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Household;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\AnnouncementNotificationService;
use App\Support\DemoStaffLogin;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Normalization guard (Phase 1): recipient baseline for announcement targeting.
 *
 * Announcements are saved through the real HTTP store (so targeting is persisted in
 * announcements.zones / age_presets / age_min_months / age_max_months / zone_mode), then the
 * recipients are read back from the SAVED announcement. A future normalized storage for
 * zones/age_presets must return exactly the same recipients.
 *
 * Fixture: one resident per household, each with a linked portal account, so the notified
 * account set equals the matched resident set.
 */
class NormalizationGuardAnnouncementRecipientsTest extends TestCase
{
    use RefreshDatabase;

    /** label => [zone, age in months] — ages kept away from preset boundaries. */
    private const RESIDENTS = [
        'R1' => ['Zone 1', 3],        // infants_0_6
        'R2' => ['Zone 1', 9],        // infants_7_11
        'R3' => ['Zone 2', 36],       // young_children
        'R4' => ['Zone 2', 30 * 12],  // adults
        'R5' => ['Zone 3', 48],       // young_children
        'R6' => ['Zone 3', 70 * 12],  // seniors
    ];

    /** @var array<string, int|string> resident label => account id */
    private array $accountByLabel = [];

    protected function setUp(): void
    {
        parent::setUp();

        // notifications is not created by the SQLite test migrations; mirror the live columns.
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
        }

        $n = 0;
        foreach (self::RESIDENTS as $label => [$zone, $ageMonths]) {
            $n++;
            $household = Household::factory()->create([
                'household_no' => sprintf('HH-%03d', 800 + $n),
                'zone' => $zone,
            ]);
            $resident = Resident::factory()->create([
                'household_id' => $household->id,
                'member_no' => sprintf('MB-%03d', 800 + $n),
                'first_name' => $label,
                'last_name' => 'Baseline',
                'relation' => 'Head',
                'birthday' => now()->subMonths($ageMonths)->subDays(10)->format('Y-m-d'),
            ]);
            $account = ResidentAccount::factory()->create([
                'resident_id' => $resident->getKey(),
                'first_name' => $label,
                'last_name' => 'Baseline',
                'email' => strtolower($label).'.baseline@example.test',
            ]);
            $this->accountByLabel[$label] = $account->getKey();
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function targetingCases(): iterable
    {
        yield 'all residents, all zones' => [
            ['audience_type' => 'all', 'zone_coverage' => 'all'],
            ['R1', 'R2', 'R3', 'R4', 'R5', 'R6'],
        ];
        yield 'all residents, selected zone + custom zone' => [
            ['audience_type' => 'all', 'zone_coverage' => 'specific', 'zones' => ['Zone 1'], 'custom_zones' => ['3']],
            ['R1', 'R2', 'R5', 'R6'],
        ];
        yield 'age presets only, all zones' => [
            ['audience_type' => 'age', 'zone_coverage' => 'all', 'age_groups' => ['infants_0_6', 'young_children']],
            ['R1', 'R3', 'R5'],
        ];
        yield 'explicit min/max age (6 months to 5 years)' => [
            ['audience_type' => 'age', 'zone_coverage' => 'all', 'age_from' => 6, 'age_from_unit' => 'months', 'age_to' => 5, 'age_to_unit' => 'years'],
            ['R2', 'R3', 'R5'],
        ];
        yield 'age preset + selected zone' => [
            ['audience_type' => 'age', 'zone_coverage' => 'specific', 'age_groups' => ['young_children'], 'zones' => ['Zone 2']],
            ['R3'],
        ];
        yield 'age preset + explicit range + selected zones' => [
            ['audience_type' => 'age', 'zone_coverage' => 'specific', 'age_groups' => ['infants_0_6'],
                'age_from' => 20, 'age_from_unit' => 'years', 'age_to' => 40, 'age_to_unit' => 'years',
                'zones' => ['Zone 1', 'Zone 2']],
            ['R1', 'R4'],
        ];
    }

    /**
     * @param  array<string, mixed>  $targeting
     * @param  list<string>  $expectedLabels
     */
    #[DataProvider('targetingCases')]
    public function test_saved_announcement_reaches_the_baseline_recipients(array $targeting, array $expectedLabels): void
    {
        $this->actingAsStaff('admin');
        $this->withSession([
            UiRole::SESSION_KEY => 'admin',
            DemoStaffLogin::SESSION_DISPLAY_NAME => 'ADMIN Staff',
        ])->post(route('announcements.store'), array_merge([
            'title' => 'Baseline announcement',
            'message' => 'Recipient baseline.',
            'date' => now()->addDay()->toDateString(),
            'time' => '08:00',
            'place' => 'Barangay Health Center',
        ], $targeting))->assertSessionHasNoErrors()->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->sole()->fresh();
        $expectedAccounts = collect($expectedLabels)->map(fn (string $l) => (string) $this->accountByLabel[$l])->sort()->values()->all();

        // Recipients computed from the SAVED targeting.
        $recipients = app(AnnouncementNotificationService::class)->recipientAccountIds($announcement)
            ->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertSame($expectedAccounts, $recipients);

        // Notifications actually fanned out on save.
        $notified = DB::table('notifications')->pluck('account_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertSame($expectedAccounts, $notified);

        // Each notification names exactly its own matched resident.
        foreach ($expectedLabels as $label) {
            $context = DB::table('notifications')->where('account_id', $this->accountByLabel[$label])->value('recipient_context');
            $this->assertSame($label.' Baseline', $context);
        }

        // Reach snapshot saved at posting time equals the matched resident count.
        $this->assertSame(count($expectedLabels), (int) $announcement->estimated_reach);
    }
}
