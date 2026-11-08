<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoHouseholdWaterSupply;
use App\Support\DisplayDate;
use App\Support\StaffRole;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FINAL-4 — announcement / EH test-date / initiated-feeding constraints
 * and MM/DD/YYYY user-facing display on touched surfaces.
 */
class Final4DateConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-04 10:00:00');
        $this->actingAsStaff(StaffRole::ADMIN);
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
    private function announcementPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Barangay Health Day',
            'message' => 'Bring immunization cards.',
            'date' => Carbon::today()->toDateString(),
            'time' => '08:00',
            'place' => 'Barangay Health Center',
            'audience_type' => 'all',
            'zone_coverage' => 'all',
        ], $overrides);
    }

    public function test_announcement_yesterday_is_rejected_with_no_write(): void
    {
        $yesterday = Carbon::yesterday()->toDateString();

        $this->from(route('announcements.create'))
            ->post(route('announcements.store'), $this->announcementPayload([
                'title' => 'Past Date Must Not Persist',
                'date' => $yesterday,
            ]))
            ->assertRedirect(route('announcements.create'))
            ->assertSessionHasErrors('date')
            ->assertSessionHasInput('title', 'Past Date Must Not Persist')
            ->assertSessionHasInput('date', $yesterday);

        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_announcement_today_and_tomorrow_are_accepted(): void
    {
        $this->post(route('announcements.store'), $this->announcementPayload([
            'title' => 'Today Notice',
            'date' => Carbon::today()->toDateString(),
        ]))->assertRedirect(route('announcements.index'));

        $this->post(route('announcements.store'), $this->announcementPayload([
            'title' => 'Tomorrow Notice',
            'date' => Carbon::tomorrow()->toDateString(),
        ]))->assertRedirect(route('announcements.index'));

        $this->assertSame(2, Announcement::query()->count());
        $this->assertSame(
            Carbon::today()->toDateString(),
            Announcement::query()->where('title', 'Today Notice')->first()?->event_date?->toDateString()
        );
        $this->assertSame(
            Carbon::tomorrow()->toDateString(),
            Announcement::query()->where('title', 'Tomorrow Notice')->first()?->event_date?->toDateString()
        );
    }

    public function test_announcement_update_to_past_is_rejected_and_row_unchanged(): void
    {
        $announcement = Announcement::factory()->create([
            'title' => 'Keep Future Title',
            'event_date' => Carbon::tomorrow()->toDateString(),
            'posted_at' => now(),
        ]);

        $this->from(route('announcements.edit', $announcement))
            ->put(route('announcements.update', $announcement), $this->announcementPayload([
                'title' => 'Should Not Save',
                'date' => Carbon::yesterday()->toDateString(),
            ]))
            ->assertRedirect(route('announcements.edit', $announcement))
            ->assertSessionHasErrors('date');

        $announcement->refresh();
        $this->assertSame('Keep Future Title', $announcement->title);
        $this->assertSame(Carbon::tomorrow()->toDateString(), $announcement->event_date->toDateString());
        $this->assertDatabaseCount('announcements', 1);
    }

    public function test_announcement_user_facing_dates_use_mm_dd_yyyy(): void
    {
        Announcement::factory()->create([
            'title' => 'Display Format Notice',
            'event_date' => '2026-09-15',
            'posted_at' => '2026-09-04',
        ]);

        $html = $this->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Display Format Notice', false)
            ->getContent();

        $this->assertStringContainsString('09/15/2026', $html);
        $this->assertStringContainsString('Posted 09/04/2026', $html);
        $this->assertStringNotContainsString('Sep 15, 2026', $html);
        $this->assertStringNotContainsString('September 15, 2026', $html);
        $this->assertSame('09/15/2026', DisplayDate::format('2026-09-15'));
    }

    public function test_announcement_create_form_sets_min_today_and_shows_field_error(): void
    {
        $this->from(route('announcements.create'))
            ->post(route('announcements.store'), $this->announcementPayload([
                'date' => Carbon::yesterday()->toDateString(),
            ]))
            ->assertRedirect(route('announcements.create'))
            ->assertSessionHasErrors('date');

        $html = $this->get(route('announcements.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('min="2026-09-04"', $html);
        $this->assertStringContainsString('Announcement date must be today or a future date.', $html);
        $this->assertStringContainsString('id="announce-date-error"', $html);
    }

    public function test_eh_step2_tomorrow_rejected_today_and_blank_accepted(): void
    {
        $householdNo = $this->seedEhStep1('121');
        $tomorrow = Carbon::tomorrow()->toDateString();
        $today = Carbon::today()->toDateString();

        $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => $tomorrow,
            'microbiological_result' => 'passed',
        ])->assertSessionHasErrors('microbiological_test_date');

        $record = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNull($record['microbiological_test_date'] ?? null);
        $this->assertNull($record['microbiological_result'] ?? null);
        $this->assertSame(1, (int) ($record['step'] ?? 0));

        $this->from(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'physicochemical_test_date' => $tomorrow,
            'physicochemical_result' => 'failed',
        ])->assertSessionHasErrors('physicochemical_test_date');

        $this->assertNull(DemoHouseholdWaterSupply::find($householdNo)['physicochemical_test_date'] ?? null);

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
            'microbiological_test_date' => $today,
            'microbiological_result' => 'passed',
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $this->assertSame($today, DemoHouseholdWaterSupply::find($householdNo)['microbiological_test_date'] ?? null);

        $this->post(route('environmental-health.household-water-supply.step2.store', [
            'householdNo' => $householdNo,
        ]), [
            'household_no' => $householdNo,
        ])->assertRedirect(route('environmental-health.household-water-supply.step3', [
            'householdNo' => $householdNo,
        ]));

        $cleared = DemoHouseholdWaterSupply::find($householdNo);
        $this->assertNull($cleared['microbiological_test_date'] ?? null);
        $this->assertNull($cleared['microbiological_result'] ?? null);
        $this->assertNull($cleared['physicochemical_test_date'] ?? null);
        $this->assertNull($cleared['physicochemical_result'] ?? null);
    }

    public function test_eh_step2_blank_page_has_max_today_and_no_static_result(): void
    {
        $householdNo = $this->seedEhStep1('HH-001');
        $today = Carbon::today()->toDateString();

        $html = $this->get(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('max="'.$today.'"', $html);
        $this->assertStringNotContainsString('lml-hws__date-icon', $html);
        $this->assertStringNotContainsString('bi-calendar3', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-hws-micro-result"[^>]*checked|name="microbiological_result"[^>]*checked/',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/data-hws-physico-result"[^>]*checked|name="physicochemical_result"[^>]*checked/',
            $html
        );
    }

    public function test_child_initiated_feeding_display_and_html_max(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $resident = Resident::factory()->create([
            'household_id' => $household->id,
            'member_no' => 'MB-010',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'birthday' => '2024-01-15',
            'sex' => 'Female',
        ]);
        $params = [
            'householdNo' => 'HH-001',
            'memberId' => 'MB-010',
        ];
        $this->actingAsStaff(StaffRole::BHW);

        $edit = $this->get(route('household-profiling.members.child-immunization.birth-history.edit', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('max="2026-09-04"', $edit);

        $this->post(route('household-profiling.members.child-immunization.birth-history.store', $params), [
            'birth_weight' => '3.10',
            'birth_length' => '49.00',
            'breastfeeding_date' => '2026-09-04',
        ])->assertRedirect();

        $html = $this->get(route('household-profiling.members.child-immunization', $params))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('09/04/2026', $html);
        $this->assertSame('09/04/2026', DisplayDate::format('2026-09-04'));
        $this->assertSame($resident->member_no, 'MB-010');
    }

    private function seedEhStep1(string $householdNo): string
    {
        $this->actingAsStaff(StaffRole::BHW);
        $this->withSession([
            UiRole::SESSION_KEY => 'bhw',
        ]);

        Household::factory()->create([
            'household_no' => $householdNo,
            'zone' => 'Zone 1',
            'street' => 'Layuan St.',
            'date_registered' => '2026-01-15',
        ]);

        $issue = $this->postJson(route('spot-mapping.plot-handoff'), [
            'household_no' => $householdNo,
            'house_head' => 'Juan Dela Cruz',
            'household_type' => 'HHTS',
            'zone' => '1',
            'lat' => 13.3811,
            'lng' => 123.4306,
            'consent' => true,
            'client_marker_id' => 'client-marker-final4',
        ]);
        $issue->assertOk();

        $this->get(route('environmental-health.household-water-supply', [
            'handoff' => (string) $issue->json('handoff_token'),
        ]))->assertRedirect();

        $this->post(route('environmental-health.household-water-supply.store'), [
            'household_no' => $householdNo,
            'water_supply_status' => 'level_i',
            'water_source_location' => 'yes',
            'water_availability' => 'yes',
        ])->assertRedirect(route('environmental-health.household-water-supply.step2', [
            'householdNo' => $householdNo,
        ]));

        return $householdNo;
    }
}
