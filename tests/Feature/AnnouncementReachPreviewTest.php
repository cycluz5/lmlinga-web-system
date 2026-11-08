<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\MaternalPregnancy;
use App\Models\Resident;
use App\Services\AnnouncementAudienceMatcher;
use App\Support\DemoStaffLogin;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AuthenticatesStaff;
use Tests\TestCase;

class AnnouncementReachPreviewTest extends TestCase
{
    use AuthenticatesStaff;
    use RefreshDatabase;

    private AnnouncementAudienceMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 10:00:00');
        $this->matcher = $this->app->make(AnnouncementAudienceMatcher::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsStaffRole(string $role = 'admin'): static
    {
        $this->actingAsStaff($role);

        return $this->withSession([
            UiRole::SESSION_KEY => $role,
            DemoStaffLogin::SESSION_DISPLAY_NAME => strtoupper($role).' Staff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $criteria
     */
    private function assertPreviewMatchesMatcher(array $payload, array $criteria): void
    {
        $expected = $this->matcher->count($criteria);

        $this->actingAsStaffRole()
            ->postJson(route('announcements.reach-preview'), $payload)
            ->assertOk()
            ->assertJson([
                'estimated_reach' => $expected,
            ]);
    }

    public function test_all_residents_all_zones_matches_matcher_count(): void
    {
        $z1 = Household::factory()->create(['zone' => 'Zone 1']);
        $z2 = Household::factory()->create(['zone' => 'Zone 2']);
        Resident::factory()->create(['household_id' => $z1->id, 'birthday' => '1990-01-01']);
        Resident::factory()->create(['household_id' => $z2->id, 'birthday' => '2010-01-01']);
        Resident::factory()->create(['household_id' => $z1->id, 'birthday' => '2000-01-01']);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'all',
                'zone_coverage' => 'all',
            ],
            [
                'target_group' => 'all',
                'zone_mode' => 'all',
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_specific_zone_matches_matcher_count(): void
    {
        $z1 = Household::factory()->create(['zone' => 'Zone 1']);
        $z3 = Household::factory()->create(['zone' => 'Zone 3']);
        Resident::factory()->create(['household_id' => $z1->id, 'birthday' => '1990-01-01']);
        Resident::factory()->create(['household_id' => $z1->id, 'birthday' => '1991-01-01']);
        Resident::factory()->create(['household_id' => $z3->id, 'birthday' => '1992-01-01']);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'all',
                'zone_coverage' => 'specific',
                'zones' => ['1'],
            ],
            [
                'target_group' => 'all',
                'zone_mode' => 'specific',
                'zones' => ['Zone 1'],
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_multiple_zones_matches_matcher_count(): void
    {
        $z1 = Household::factory()->create(['zone' => 'Zone 1']);
        $z2 = Household::factory()->create(['zone' => 'Zone 2']);
        $z5 = Household::factory()->create(['zone' => 'Zone 5']);
        Resident::factory()->create(['household_id' => $z1->id, 'birthday' => '1990-01-01']);
        Resident::factory()->create(['household_id' => $z2->id, 'birthday' => '1991-01-01']);
        Resident::factory()->create(['household_id' => $z5->id, 'birthday' => '1992-01-01']);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'all',
                'zone_coverage' => 'specific',
                'zones' => ['1', '5'],
            ],
            [
                'target_group' => 'all',
                'zone_mode' => 'specific',
                'zones' => ['Zone 1', 'Zone 5'],
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_infant_age_targeting_matches_matcher_count(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => now()->subMonths(3)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => now()->subMonths(9)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1990-01-01',
        ]);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'age',
                'zone_coverage' => 'all',
                'age_groups' => ['infants_0_6', 'infants_7_11'],
            ],
            [
                'target_group' => 'age',
                'age_presets' => ['infants_0_6', 'infants_7_11'],
                'age_range_months' => ['min' => null, 'max' => null],
                'zone_mode' => 'all',
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_custom_age_range_matches_matcher_count(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 2']);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => now()->subYears(5)->toDateString(),
        ]);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => now()->subYears(20)->toDateString(),
        ]);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'age',
                'zone_coverage' => 'all',
                'age_from' => 0,
                'age_from_unit' => 'years',
                'age_to' => 10,
                'age_to_unit' => 'years',
            ],
            [
                'target_group' => 'age',
                'age_presets' => [],
                'age_range_months' => ['min' => 0, 'max' => 120],
                'zone_mode' => 'all',
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_active_maternal_matches_matcher_count(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1']);
        $active = Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1995-01-01',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1997-01-01',
        ]);
        MaternalPregnancy::factory()->create([
            'resident_id' => $active->id,
            'status' => MaternalPregnancy::STATUS_ACTIVE,
        ]);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'active_maternal',
                'zone_coverage' => 'all',
            ],
            [
                'target_group' => 'active_maternal',
                'zone_mode' => 'all',
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_active_fp_user_matches_matcher_count(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 1']);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1990-01-01',
            'fp_user' => 'Yes',
        ]);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1991-01-01',
            'fp_user' => 'No',
        ]);

        $this->assertPreviewMatchesMatcher(
            [
                'audience_type' => 'active_fp_user',
                'zone_coverage' => 'all',
            ],
            [
                'target_group' => 'active_fp_user',
                'zone_mode' => 'all',
                'as_of' => Carbon::today()->startOfDay(),
            ],
        );
    }

    public function test_zero_match_returns_zero(): void
    {
        $hh = Household::factory()->create(['zone' => 'Zone 4']);
        Resident::factory()->create([
            'household_id' => $hh->id,
            'birthday' => '1990-01-01',
        ]);

        $this->actingAsStaffRole()
            ->postJson(route('announcements.reach-preview'), [
                'audience_type' => 'all',
                'zone_coverage' => 'specific',
                'zones' => ['1'],
            ])
            ->assertOk()
            ->assertJson([
                'estimated_reach' => 0,
            ]);
    }

    public function test_incomplete_age_targeting_is_rejected(): void
    {
        $this->actingAsStaffRole()
            ->postJson(route('announcements.reach-preview'), [
                'audience_type' => 'age',
                'zone_coverage' => 'all',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['age_groups']);
    }

    public function test_guest_cannot_preview_reach(): void
    {
        $this->postJson(route('announcements.reach-preview'), [
            'audience_type' => 'all',
            'zone_coverage' => 'all',
        ])->assertUnauthorized();
    }
}
